<?php

declare(strict_types=1);

namespace Mailika;

use Dotenv\Dotenv;
use Mailika\Audit\AuditEventRepository;
use Mailika\Audit\AuditLogger;
use Mailika\Audit\NullAuditEventRepository;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\LoginPrefillStore;
use Mailika\Config\Config;
use Mailika\Contact\ContactRepository;
use Mailika\Contact\ContactGroupRepository;
use Mailika\Contact\LdapContactDirectory;
use Mailika\Contact\SessionContactGroupRepository;
use Mailika\Contact\SessionContactRepository;
use Mailika\Contact\VcardParser;
use Mailika\Controller\AuthController;
use Mailika\Controller\AccountController;
use Mailika\Controller\ComposeController;
use Mailika\Controller\ContactController;
use Mailika\Controller\DraftController;
use Mailika\Controller\FilterController;
use Mailika\Controller\FolderAclController;
use Mailika\Controller\FolderController;
use Mailika\Controller\HealthController;
use Mailika\Controller\IdentityController;
use Mailika\Controller\MailboxController;
use Mailika\Controller\MessageActionController;
use Mailika\Controller\SettingsController;
use Mailika\Crypto\OpenPgpLeakGuard;
use Mailika\Database\ApplicationRepositories;
use Mailika\Database\Connection;
use Mailika\Draft\DraftRepository;
use Mailika\Draft\SessionDraftRepository;
use Mailika\Filter\ManageSievePublisher;
use Mailika\Filter\SessionSieveRuleRepository;
use Mailika\Filter\SieveRuleRepository;
use Mailika\Filter\SieveScriptAnalyzer;
use Mailika\Filter\SieveScriptCompiler;
use Mailika\Filter\SieveScriptImporter;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Http\Router;
use Mailika\Identity\IdentityRepository;
use Mailika\Identity\SessionIdentityRepository;
use Mailika\Mail\FixtureMailboxClient;
use Mailika\Mail\MailAccountProfileRepository;
use Mailika\Mail\SymfonySmtpSender;
use Mailika\Mail\MessageMetadataCacheRepository;
use Mailika\Mail\SavedSearchRepository;
use Mailika\Mail\SessionMailAccountProfileRepository;
use Mailika\Mail\SessionMessageMetadataCacheRepository;
use Mailika\Mail\SessionSavedSearchRepository;
use Mailika\Mail\WebklexMailboxClient;
use Mailika\Mail\PhpImapMailboxClient;
use Mailika\Module\CoreModules;
use Mailika\Preferences\LocaleCatalog;
use Mailika\Preferences\PreferencesRepository;
use Mailika\Preferences\Preferences;
use Mailika\Preferences\SessionPreferencesRepository;
use Mailika\Security\AttachmentPolicy;
use Mailika\Security\CsrfTokenManager;
use Mailika\Security\HtmlSanitizer;
use Mailika\Security\RateLimiter;
use Mailika\Security\SecurityHeaders;
use Mailika\Security\SessionManager;
use Mailika\Support\View;
use Throwable;

final readonly class Bootstrap
{
    public function __construct(
        private Config $config,
        private Router $router,
        private SecurityHeaders $securityHeaders,
        private View $view,
    ) {
    }

    public static function create(string $root, ?Request $request = null): self
    {
        $request ??= Request::fromGlobals();
        if (is_file($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }

        $config = Config::fromEnvironment($root);
        if (!self::statelessRequest($request)) {
            (new SessionManager($config))->start();
        }

        $connection = new Connection($config);
        $modules = CoreModules::fromConfig($config);
        $repositories = self::dataRepositories($config, $connection);
        $vault = new CredentialVault($config);
        $view = new View(
            $root . '/templates',
            $root . '/public',
            static function () use ($vault, $repositories, $request): Preferences {
                $credentials = $vault->current();
                if ($credentials !== null) {
                    return $repositories->preferences->get($credentials->email);
                }

                $acceptLanguage = $request->server['HTTP_ACCEPT_LANGUAGE'] ?? '';
                $acceptLanguage = is_scalar($acceptLanguage) ? (string) $acceptLanguage : '';

                return new Preferences(LocaleCatalog::negotiateAcceptLanguage($acceptLanguage));
            },
        );
        $csrf = new CsrfTokenManager();
        $audit = new AuditLogger($config, $repositories->auditEvents);
        $loginPrefill = new LoginPrefillStore();
        $mailboxClient = match ($config->string('mail.imap_adapter')) {
            'native' => new PhpImapMailboxClient($config),
            'fixture' => new FixtureMailboxClient(),
            default => new WebklexMailboxClient($config),
        };
        $smtpSender = new SymfonySmtpSender($config);
        $sanitizer = new HtmlSanitizer($config);
        $attachmentPolicy = new AttachmentPolicy($config);
        $rateLimiter = new RateLimiter($root . '/storage/cache', $config);

        $auth = new AuthController($config, $view, $csrf, $vault, $mailboxClient, $rateLimiter, $audit, $loginPrefill);
        $mailbox = new MailboxController(
            $view,
            $csrf,
            $vault,
            $mailboxClient,
            $sanitizer,
            $repositories->savedSearches,
            $repositories->mailAccountProfiles,
            $repositories->messageMetadataCache,
            $repositories->preferences,
        );
        $compose = new ComposeController(
            $view,
            $csrf,
            $vault,
            $smtpSender,
            $mailboxClient,
            $attachmentPolicy,
            $repositories->contacts,
            $repositories->identities,
            $repositories->drafts,
            new OpenPgpLeakGuard(),
            $audit,
        );
        $settings = new SettingsController($view, $csrf, $vault, $repositories->preferences, $audit);
        $accounts = new AccountController(
            $config,
            $view,
            $csrf,
            $vault,
            $repositories->mailAccountProfiles,
            $audit,
            $loginPrefill,
        );
        $health = new HealthController($config, $connection, $modules);
        $contacts = new ContactController(
            $view,
            $csrf,
            $vault,
            $repositories->contacts,
            $repositories->contactGroups,
            new VcardParser(),
            new LdapContactDirectory($config),
            $audit,
        );
        $identities = new IdentityController($view, $csrf, $vault, $repositories->identities, $audit);
        $folders = new FolderController($csrf, $vault, $mailboxClient, $audit);
        $folderAcl = new FolderAclController($csrf, $vault, $mailboxClient, $audit);
        $actions = new MessageActionController(
            $csrf,
            $vault,
            $mailboxClient,
            $attachmentPolicy,
            $audit,
            $repositories->messageMetadataCache,
        );
        $drafts = new DraftController($view, $csrf, $vault, $repositories->drafts, $audit);
        $sieveAnalyzer = new SieveScriptAnalyzer();
        $filters = new FilterController(
            $view,
            $csrf,
            $vault,
            $repositories->sieveRules,
            new SieveScriptCompiler(),
            $sieveAnalyzer,
            new SieveScriptImporter($sieveAnalyzer),
            new ManageSievePublisher($config),
            $audit,
        );

        $router = new Router();
        $router->get('/', fn (Request $request): Response => Response::redirect('/mailbox'));
        $router->get('/login', [$auth, 'showLogin']);
        $router->post('/login', [$auth, 'login']);
        $router->post('/logout', [$auth, 'logout']);
        $router->get('/mailbox', [$mailbox, 'index']);
        $router->get('/search', [$mailbox, 'search']);
        $router->get('/message', [$mailbox, 'message']);
        $router->post('/message/action', [$actions, 'update']);
        $router->get('/attachment', [$actions, 'attachment']);
        $router->post('/searches', [$mailbox, 'saveSearch']);
        $router->post('/searches/delete', [$mailbox, 'deleteSearch']);
        $router->get('/compose', [$compose, 'show']);
        $router->post('/compose', [$compose, 'send']);
        $router->get('/contacts', [$contacts, 'index']);
        $router->post('/contacts', [$contacts, 'save']);
        $router->post('/contacts/delete', [$contacts, 'delete']);
        $router->post('/contacts/groups', [$contacts, 'createGroup']);
        $router->post('/contacts/groups/delete', [$contacts, 'deleteGroup']);
        $router->post('/contacts/import', [$contacts, 'import']);
        $router->get('/contacts/export', [$contacts, 'export']);
        $router->get('/identities', [$identities, 'index']);
        $router->post('/identities', [$identities, 'save']);
        $router->post('/identities/delete', [$identities, 'delete']);
        $router->post('/folders/create', [$folders, 'create']);
        $router->post('/folders/rename', [$folders, 'rename']);
        $router->post('/folders/delete', [$folders, 'delete']);
        $router->post('/folders/acl', [$folderAcl, 'save']);
        $router->post('/folders/acl/delete', [$folderAcl, 'delete']);
        $router->get('/drafts', [$drafts, 'index']);
        $router->post('/drafts/delete', [$drafts, 'delete']);
        $router->get('/filters', [$filters, 'index']);
        $router->post('/filters', [$filters, 'save']);
        $router->post('/filters/inspect', [$filters, 'inspect']);
        $router->post('/filters/import', [$filters, 'import']);
        $router->post('/filters/publish', [$filters, 'publish']);
        $router->post('/filters/delete', [$filters, 'delete']);
        $router->get('/accounts', [$accounts, 'index']);
        $router->post('/accounts', [$accounts, 'save']);
        $router->post('/accounts/select', [$accounts, 'select']);
        $router->post('/accounts/delete', [$accounts, 'delete']);
        $router->get('/settings', [$settings, 'show']);
        $router->post('/settings', [$settings, 'save']);
        $router->get('/healthz', [$health, 'show']);
        $router->get('/readyz', [$health, 'ready']);
        $router->get('/metrics', [$health, 'metrics']);

        return new self($config, $router, new SecurityHeaders($config), $view);
    }

    private static function dataRepositories(Config $config, Connection $connection): ApplicationRepositories
    {
        $mode = $config->string('data.store');
        $useDatabase = $mode === 'database' || ($mode === 'auto' && $connection->driverAvailable());

        if (!$useDatabase) {
            return new ApplicationRepositories(
                new SessionContactRepository(),
                new SessionContactGroupRepository(),
                new SessionIdentityRepository(),
                new SessionDraftRepository(),
                new SessionSieveRuleRepository(),
                new SessionMailAccountProfileRepository(),
                new SessionSavedSearchRepository(),
                new SessionMessageMetadataCacheRepository(),
                new SessionPreferencesRepository(),
                new NullAuditEventRepository(),
            );
        }

        $pdo = $connection->pdo();

        return new ApplicationRepositories(
            new ContactRepository($pdo),
            new ContactGroupRepository($pdo),
            new IdentityRepository($pdo),
            new DraftRepository($pdo),
            new SieveRuleRepository($pdo),
            new MailAccountProfileRepository($pdo),
            new SavedSearchRepository($pdo),
            new MessageMetadataCacheRepository($pdo),
            new PreferencesRepository($pdo),
            new AuditEventRepository($pdo),
        );
    }

    private static function statelessRequest(Request $request): bool
    {
        return in_array($request->path, ['/healthz', '/readyz', '/metrics'], true);
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->router->dispatch($request);
        } catch (Throwable $exception) {
            $response = $this->renderException($exception);
        }

        return $this->securityHeaders->apply($response, $request);
    }

    private function renderException(Throwable $exception): Response
    {
        $status = 500;
        $data = ['title' => 'Server error', 'exception' => null];

        if ($this->config->bool('app.debug')) {
            $data['exception'] = $exception;
        }

        return new Response($this->view->render('errors/500', $data), $status);
    }
}

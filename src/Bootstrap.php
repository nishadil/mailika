<?php

declare(strict_types=1);

namespace Mailika;

use Dotenv\Dotenv;
use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Config\Config;
use Mailika\Controller\AuthController;
use Mailika\Controller\ComposeController;
use Mailika\Controller\HealthController;
use Mailika\Controller\MailboxController;
use Mailika\Controller\SettingsController;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Http\Router;
use Mailika\Mail\PhpImapMailboxClient;
use Mailika\Mail\SymfonySmtpSender;
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

    public static function create(string $root): self
    {
        if (is_file($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }

        $config = Config::fromEnvironment($root);
        (new SessionManager($config))->start();

        $view = new View($root . '/templates', $root . '/public');
        $csrf = new CsrfTokenManager();
        $vault = new CredentialVault($config);
        $audit = new AuditLogger($config);
        $mailboxClient = new PhpImapMailboxClient($config);
        $smtpSender = new SymfonySmtpSender();
        $sanitizer = new HtmlSanitizer($config);
        $rateLimiter = new RateLimiter($root . '/storage/cache');

        $auth = new AuthController($config, $view, $csrf, $vault, $mailboxClient, $rateLimiter, $audit);
        $mailbox = new MailboxController($view, $csrf, $vault, $mailboxClient, $sanitizer);
        $compose = new ComposeController($config, $view, $csrf, $vault, $smtpSender);
        $settings = new SettingsController($config, $view, $csrf, $vault);
        $health = new HealthController($config);

        $router = new Router();
        $router->get('/', fn (Request $request): Response => Response::redirect('/mailbox'));
        $router->get('/login', [$auth, 'showLogin']);
        $router->post('/login', [$auth, 'login']);
        $router->post('/logout', [$auth, 'logout']);
        $router->get('/mailbox', [$mailbox, 'index']);
        $router->get('/message', [$mailbox, 'message']);
        $router->get('/compose', [$compose, 'show']);
        $router->post('/compose', [$compose, 'send']);
        $router->get('/settings', [$settings, 'show']);
        $router->post('/settings', [$settings, 'save']);
        $router->get('/healthz', [$health, 'show']);

        return new self($config, $router, new SecurityHeaders($config), $view);
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

<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Audit\AuditEventRepositoryInterface;
use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Controller\FilterController;
use Mailika\Filter\SessionSieveRuleRepository;
use Mailika\Filter\SieveRule;
use Mailika\Filter\SieveScriptAnalyzer;
use Mailika\Filter\SieveScriptCompiler;
use Mailika\Filter\SieveScriptImporter;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Security\CsrfTokenManager;
use Mailika\Support\View;
use Mailika\Tests\Fixtures\FakeSievePublisher;
use Mailika\Tests\Fixtures\InMemoryAuditEventRepository;
use PHPUnit\Framework\TestCase;

final class FilterControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('f', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_ENV['APP_KEY'], $_ENV['LOG_PATH']);
    }

    public function testIndexRendersFilterFormAndScriptPreview(): void
    {
        $controller = $this->controller(new SessionSieveRuleRepository());
        $this->login();

        $html = $this->send($controller->index(new Request('GET', '/filters', [], [], [], [], [])));

        self::assertStringContainsString('Mail filters', $html);
        self::assertStringContainsString('name="match_field"', $html);
        self::assertStringContainsString('Sieve script preview', $html);
    }

    public function testSaveValidatesRequiredFileintoTarget(): void
    {
        $repository = new SessionSieveRuleRepository();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($repository, $csrf);
        $credentials = $this->login();

        $response = $controller->save(new Request('POST', '/filters', [], [
            '_csrf' => $csrf->token(),
            'name' => 'Invoices',
            'enabled' => '1',
            'match_field' => 'subject',
            'match_operator' => 'contains',
            'match_value' => 'invoice',
            'action' => 'fileinto',
            'action_target' => '',
            'stop_processing' => '1',
        ], [], [], []));

        self::assertSame(422, $response->status());
        self::assertSame([], $repository->listForMailbox($credentials->email));
    }

    public function testSaveSupportsRedirectAndVacationActions(): void
    {
        $repository = new SessionSieveRuleRepository();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($repository, $csrf);
        $credentials = $this->login();
        $token = $csrf->token();

        $controller->save(new Request('POST', '/filters', [], [
            '_csrf' => $token,
            'name' => 'Escalate',
            'enabled' => '1',
            'match_field' => 'subject',
            'match_operator' => 'contains',
            'match_value' => 'urgent',
            'action' => 'redirect',
            'action_target' => 'ops@example.com',
            'stop_processing' => '1',
        ], [], [], []));

        $controller->save(new Request('POST', '/filters', [], [
            '_csrf' => $token,
            'name' => 'Away',
            'enabled' => '1',
            'match_field' => 'to',
            'match_operator' => 'is',
            'match_value' => 'smoke@example.com',
            'action' => 'vacation',
            'action_target' => 'I am away.',
            'vacation_days' => '7',
            'vacation_subject' => 'Away from mail',
            'vacation_addresses' => "smoke@example.com\nteam@example.com",
            'vacation_excluded_senders' => "noreply@example.com\nalerts@example.com",
            'stop_processing' => '1',
        ], [], [], []));

        $rules = $repository->listForMailbox($credentials->email);

        self::assertCount(2, $rules);
        self::assertSame('vacation', $rules[0]->action);
        self::assertSame(7, $rules[0]->vacationDays);
        self::assertSame('Away from mail', $rules[0]->vacationSubject);
        self::assertSame(['noreply@example.com', 'alerts@example.com'], $rules[0]->vacationExcludedSenders);
        self::assertSame(['smoke@example.com', 'team@example.com'], $rules[0]->vacationAddresses);
        self::assertSame('redirect', $rules[1]->action);
    }

    public function testSaveRejectsInvalidVacationDays(): void
    {
        $repository = new SessionSieveRuleRepository();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($repository, $csrf);
        $credentials = $this->login();

        $response = $controller->save(new Request('POST', '/filters', [], [
            '_csrf' => $csrf->token(),
            'name' => 'Away',
            'enabled' => '1',
            'match_field' => 'subject',
            'match_operator' => 'contains',
            'match_value' => 'anything',
            'action' => 'vacation',
            'action_target' => 'I am away.',
            'vacation_days' => '366',
            'stop_processing' => '1',
        ], [], [], []));

        self::assertSame(422, $response->status());
        self::assertSame([], $repository->listForMailbox($credentials->email));
    }

    public function testSaveRejectsInvalidVacationExcludedSender(): void
    {
        $repository = new SessionSieveRuleRepository();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($repository, $csrf);
        $credentials = $this->login();

        $response = $controller->save(new Request('POST', '/filters', [], [
            '_csrf' => $csrf->token(),
            'name' => 'Away',
            'enabled' => '1',
            'match_field' => 'subject',
            'match_operator' => 'contains',
            'match_value' => 'anything',
            'action' => 'vacation',
            'action_target' => 'I am away.',
            'vacation_excluded_senders' => 'not an email',
            'stop_processing' => '1',
        ], [], [], []));

        self::assertSame(422, $response->status());
        self::assertSame([], $repository->listForMailbox($credentials->email));
    }

    public function testSaveRejectsInvalidVacationAddress(): void
    {
        $repository = new SessionSieveRuleRepository();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($repository, $csrf);
        $credentials = $this->login();

        $response = $controller->save(new Request('POST', '/filters', [], [
            '_csrf' => $csrf->token(),
            'name' => 'Away',
            'enabled' => '1',
            'match_field' => 'subject',
            'match_operator' => 'contains',
            'match_value' => 'anything',
            'action' => 'vacation',
            'action_target' => 'I am away.',
            'vacation_addresses' => 'not an email',
            'stop_processing' => '1',
        ], [], [], []));

        self::assertSame(422, $response->status());
        self::assertSame([], $repository->listForMailbox($credentials->email));
    }

    public function testSaveRejectsInvalidRedirectTarget(): void
    {
        $repository = new SessionSieveRuleRepository();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($repository, $csrf);
        $credentials = $this->login();

        $response = $controller->save(new Request('POST', '/filters', [], [
            '_csrf' => $csrf->token(),
            'name' => 'Bad redirect',
            'enabled' => '1',
            'match_field' => 'subject',
            'match_operator' => 'contains',
            'match_value' => 'urgent',
            'action' => 'redirect',
            'action_target' => 'not an email',
            'stop_processing' => '1',
        ], [], [], []));

        self::assertSame(422, $response->status());
        self::assertSame([], $repository->listForMailbox($credentials->email));
    }

    public function testPublishCompilesRulesAndWritesAuditEvent(): void
    {
        $repository = new SessionSieveRuleRepository();
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $publisher = new FakeSievePublisher();
        $controller = $this->controller($repository, $csrf, $events, $publisher);
        $credentials = $this->login();

        $controller->save(new Request('POST', '/filters', [], [
            '_csrf' => $csrf->token(),
            'name' => 'Invoices',
            'enabled' => '1',
            'match_field' => 'subject',
            'match_operator' => 'contains',
            'match_value' => 'invoice',
            'action' => 'fileinto',
            'action_target' => 'Archive',
            'stop_processing' => '1',
        ], [], [], []));

        $response = $controller->publish(new Request('POST', '/filters/publish', [], [
            '_csrf' => $csrf->token(),
        ], [], [], []));

        self::assertSame(200, $response->status());
        self::assertNotNull($publisher->publishedScript);
        self::assertStringContainsString('fileinto "Archive";', $publisher->publishedScript);
        self::assertSame('filter.published', $events->records[1]['event_type']);
        self::assertSame($credentials->email, $events->records[1]['metadata']['mailbox']);
        self::assertSame('mailika-test', $events->records[1]['metadata']['script_name']);
    }

    public function testPublishFailureUsesGenericErrorAndWritesAuditEvent(): void
    {
        $repository = new SessionSieveRuleRepository();
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $controller = $this->controller($repository, $csrf, $events, new FakeSievePublisher(true, true));
        $credentials = $this->login();

        $response = $controller->publish(new Request('POST', '/filters/publish', [], [
            '_csrf' => $csrf->token(),
        ], [], [], []));
        $html = $this->send($response);

        self::assertSame(502, $response->status());
        self::assertStringContainsString('Filters could not be published', $html);
        self::assertStringNotContainsString('Simulated ManageSieve failure', $html);
        self::assertSame('filter.publish_failed', $events->records[0]['event_type']);
        self::assertSame($credentials->email, $events->records[0]['metadata']['mailbox']);
    }

    public function testInspectSummarizesPastedSieveScriptAndWritesAuditEvent(): void
    {
        $repository = new SessionSieveRuleRepository();
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $controller = $this->controller($repository, $csrf, $events);
        $credentials = $this->login();

        $response = $controller->inspect(new Request('POST', '/filters/inspect', [], [
            '_csrf' => $csrf->token(),
            'sieve_script' => "require [\"fileinto\", \"include\"];\npipe \"spamc\";\n",
        ], [], [], []));
        $html = $this->send($response);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Sieve inspection', $html);
        self::assertStringContainsString('include', $html);
        self::assertStringContainsString('pipe', $html);
        self::assertStringContainsString('cannot be imported automatically', $html);
        self::assertSame('filter.script_inspected', $events->records[0]['event_type']);
        self::assertSame($credentials->email, $events->records[0]['metadata']['mailbox']);
        self::assertSame('false', $events->records[0]['metadata']['mailika_owned']);
    }

    public function testImportStoresMailikaGeneratedRulesAndWritesAuditEvent(): void
    {
        $repository = new SessionSieveRuleRepository();
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $controller = $this->controller($repository, $csrf, $events);
        $credentials = $this->login();
        $script = (new SieveScriptCompiler())->compile([
            new SieveRule(
                'Imported invoices',
                true,
                'subject',
                'contains',
                'invoice',
                'fileinto',
                'Archive',
            ),
        ]);

        $response = $controller->import(new Request('POST', '/filters/import', [], [
            '_csrf' => $csrf->token(),
            'sieve_script' => $script,
        ], [], [], []));
        $html = $this->send($response);
        $rules = $repository->listForMailbox($credentials->email);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Imported 1 Mailika-generated filter(s).', $html);
        self::assertCount(1, $rules);
        self::assertSame('Imported invoices', $rules[0]->name);
        self::assertSame('Archive', $rules[0]->actionTarget);
        self::assertSame('filter.script_imported', $events->records[0]['event_type']);
        self::assertSame('1', $events->records[0]['metadata']['rules']);
    }

    public function testImportRejectsExternalScriptAndWritesAuditEvent(): void
    {
        $repository = new SessionSieveRuleRepository();
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $controller = $this->controller($repository, $csrf, $events);
        $credentials = $this->login();

        $response = $controller->import(new Request('POST', '/filters/import', [], [
            '_csrf' => $csrf->token(),
            'sieve_script' => "require [\"fileinto\", \"include\"];\npipe \"spamc\";\n",
        ], [], [], []));
        $html = $this->send($response);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Only Mailika-generated Sieve scripts can be imported automatically.', $html);
        self::assertSame([], $repository->listForMailbox($credentials->email));
        self::assertSame('filter.script_import_failed', $events->records[0]['event_type']);
        self::assertSame('false', $events->records[0]['metadata']['mailika_owned']);
    }

    public function testSaveAndDeleteRequireCsrfAndWriteAuditEvents(): void
    {
        $repository = new SessionSieveRuleRepository();
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $controller = $this->controller($repository, $csrf, $events);
        $credentials = $this->login();

        $controller->save(new Request('POST', '/filters', [], [
            '_csrf' => 'invalid',
            'name' => 'Invoices',
            'enabled' => '1',
            'match_field' => 'subject',
            'match_operator' => 'contains',
            'match_value' => 'invoice',
            'action' => 'discard',
            'stop_processing' => '1',
        ], [], [], []));
        self::assertSame([], $repository->listForMailbox($credentials->email));

        $token = $csrf->token();
        $controller->save(new Request('POST', '/filters', [], [
            '_csrf' => $token,
            'name' => 'Invoices',
            'enabled' => '1',
            'match_field' => 'subject',
            'match_operator' => 'contains',
            'match_value' => 'invoice',
            'action' => 'discard',
            'stop_processing' => '1',
        ], [], [], []));
        self::assertCount(1, $repository->listForMailbox($credentials->email));

        $controller->delete(new Request('POST', '/filters/delete', [], [
            '_csrf' => $token,
            'id' => '1',
        ], [], [], []));

        self::assertSame(['filter.saved', 'filter.deleted'], array_column($events->records, 'event_type'));
        self::assertSame('Invoices', $events->records[0]['metadata']['rule']);
        self::assertSame('subject', $events->records[0]['metadata']['match_field']);
        self::assertSame('discard', $events->records[0]['metadata']['action']);
        self::assertSame('Invoices', $events->records[1]['metadata']['rule']);
        self::assertSame([], $repository->listForMailbox($credentials->email));
    }

    private function controller(
        SessionSieveRuleRepository $repository,
        ?CsrfTokenManager $csrf = null,
        ?AuditEventRepositoryInterface $auditEvents = null,
        ?FakeSievePublisher $publisher = null,
    ): FilterController {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $analyzer = new SieveScriptAnalyzer();

        return new FilterController(
            new View($root . '/templates', $root . '/public'),
            $csrf ?? new CsrfTokenManager(),
            new CredentialVault($config),
            $repository,
            new SieveScriptCompiler(),
            $analyzer,
            new SieveScriptImporter($analyzer),
            $publisher ?? new FakeSievePublisher(false),
            $this->auditLogger($auditEvents),
        );
    }

    private function login(): MailboxCredentials
    {
        $credentials = new MailboxCredentials(
            'smoke@example.com',
            'secret',
            'imap.example.com',
            993,
            true,
            'smtp.example.com',
            587,
            'starttls',
        );

        (new CredentialVault(Config::fromEnvironment(dirname(__DIR__, 2))))->store($credentials);
        return $credentials;
    }

    private function send(Response $response): string
    {
        ob_start();
        $response->send();
        return (string) ob_get_clean();
    }

    private function auditLogger(?AuditEventRepositoryInterface $events = null): AuditLogger
    {
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . '/mailika-filter-audit-' . bin2hex(random_bytes(4)) . '.log';

        return new AuditLogger(
            Config::fromEnvironment(dirname(__DIR__, 2)),
            $events ?? new InMemoryAuditEventRepository(),
        );
    }
}

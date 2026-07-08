<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Audit\AuditEventRepositoryInterface;
use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\LoginPrefillStore;
use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Controller\AccountController;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Mail\MailAccountProfile;
use Mailika\Mail\SessionMailAccountProfileRepository;
use Mailika\Security\CsrfTokenManager;
use Mailika\Support\View;
use Mailika\Tests\Fixtures\InMemoryAuditEventRepository;
use PHPUnit\Framework\TestCase;

final class AccountControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('a', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_ENV['APP_KEY'], $_ENV['LOG_PATH']);
    }

    public function testIndexRendersCurrentAccountProfileWithoutPasswordFields(): void
    {
        $controller = $this->controller(new SessionMailAccountProfileRepository());
        $this->login();

        $html = $this->send($controller->index(new Request('GET', '/accounts', [], [], [], [], [])));

        self::assertStringContainsString('Account profiles', $html);
        self::assertStringContainsString('Save current account profile', $html);
        self::assertStringContainsString('imap.example.com', $html);
        self::assertStringContainsString('smtp.example.com', $html);
        self::assertStringNotContainsString('name="password"', $html);
        self::assertStringNotContainsString('secret-password', $html);
    }

    public function testSaveRejectsInvalidHostAndKeepsRepositoryEmpty(): void
    {
        $repository = new SessionMailAccountProfileRepository();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($repository, $csrf);
        $credentials = $this->login();

        $response = $controller->save(new Request('POST', '/accounts', [], [
            '_csrf' => $csrf->token(),
            'label' => 'Bad host',
            'email' => 'bad@example.com',
            'imap_host' => 'bad_host!',
            'imap_port' => '993',
            'imap_tls' => '1',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_tls' => 'starttls',
        ], [], [], []));

        self::assertSame(422, $response->status());
        self::assertCount(0, $repository->listForMailbox($credentials->email));
        self::assertStringContainsString('Enter a valid account profile.', $this->send($response));
    }

    public function testSaveRejectsPlaintextProfileWhenTlsIsRequired(): void
    {
        $repository = new SessionMailAccountProfileRepository();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($repository, $csrf);
        $credentials = $this->login();

        $response = $controller->save(new Request('POST', '/accounts', [], [
            '_csrf' => $csrf->token(),
            'label' => 'Plaintext',
            'email' => 'plain@example.com',
            'imap_host' => 'imap.example.com',
            'imap_port' => '143',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '25',
            'smtp_tls' => 'none',
        ], [], [], []));

        self::assertSame(422, $response->status());
        self::assertCount(0, $repository->listForMailbox($credentials->email));
    }

    public function testSaveRejectsInvalidProfilePorts(): void
    {
        $repository = new SessionMailAccountProfileRepository();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($repository, $csrf);
        $credentials = $this->login();

        $response = $controller->save(new Request('POST', '/accounts', [], [
            '_csrf' => $csrf->token(),
            'label' => 'Bad ports',
            'email' => 'bad@example.com',
            'imap_host' => 'imap.example.com',
            'imap_port' => '0',
            'imap_tls' => '1',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '65536',
            'smtp_tls' => 'starttls',
        ], [], [], []));

        self::assertSame(422, $response->status());
        self::assertCount(0, $repository->listForMailbox($credentials->email));
        self::assertStringContainsString('Enter a valid account profile.', $this->send($response));
    }

    public function testSaveAndDeleteRequireCsrfAndWriteSecretFreeAuditEvents(): void
    {
        $repository = new SessionMailAccountProfileRepository();
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $controller = $this->controller($repository, $csrf, $events);
        $credentials = $this->login();

        $controller->save(new Request('POST', '/accounts', [], [
            '_csrf' => 'invalid',
            'label' => 'Work',
            'email' => 'work@example.com',
            'imap_host' => 'imap.work.example.com',
            'imap_port' => '993',
            'imap_tls' => '1',
            'smtp_host' => 'smtp.work.example.com',
            'smtp_port' => '587',
            'smtp_tls' => 'starttls',
        ], [], [], []));
        self::assertCount(0, $repository->listForMailbox($credentials->email));

        $token = $csrf->token();
        $controller->save(new Request('POST', '/accounts', [], [
            '_csrf' => $token,
            'label' => 'Work',
            'email' => 'work@example.com',
            'imap_host' => 'imap.work.example.com',
            'imap_port' => '993',
            'imap_tls' => '1',
            'smtp_host' => 'smtp.work.example.com',
            'smtp_port' => '587',
            'smtp_tls' => 'starttls',
        ], [], [], []));
        $profiles = $repository->listForMailbox($credentials->email);
        if ($profiles === []) {
            self::fail('Expected a saved account profile.');
        }

        $profile = $profiles[0];
        self::assertSame('work@example.com', $profile->email);

        $controller->delete(new Request('POST', '/accounts/delete', [], [
            '_csrf' => $token,
            'id' => (string) $profile->id,
        ], [], [], []));

        self::assertSame(
            ['account_profile.saved', 'account_profile.deleted'],
            array_column($events->records, 'event_type'),
        );
        self::assertSame($credentials->email, $events->records[0]['metadata']['mailbox']);
        self::assertSame('work@example.com', $events->records[0]['metadata']['profile']);
        self::assertSame('imap.work.example.com', $events->records[0]['metadata']['imap_host']);
        self::assertArrayNotHasKey('password', $events->records[0]['metadata']);
        self::assertArrayNotHasKey('secret', $events->records[0]['metadata']);
        self::assertSame('work@example.com', $events->records[1]['metadata']['profile']);
        self::assertSame([], $repository->listForMailbox($credentials->email));
    }

    public function testSelectProfileClearsCredentialsAndStoresSecretFreeLoginPrefill(): void
    {
        $repository = new SessionMailAccountProfileRepository();
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $prefill = new LoginPrefillStore();
        $controller = $this->controller($repository, $csrf, $events, $prefill);
        $credentials = $this->login();
        $repository->save($credentials->email, new MailAccountProfile(
            'Work',
            'work@example.com',
            'imap.work.example.com',
            993,
            true,
            'smtp.work.example.com',
            587,
            'starttls',
        ));
        $profile = $repository->listForMailbox($credentials->email)[0];

        $response = $controller->select(new Request('POST', '/accounts/select', [], [
            '_csrf' => $csrf->token(),
            'id' => (string) $profile->id,
        ], [], [], []));

        self::assertSame('/login', $response->headers()['Location']);
        self::assertFalse((new CredentialVault($this->config()))->isAuthenticated());
        self::assertSame([
            'email' => 'work@example.com',
            'imap_host' => 'imap.work.example.com',
            'imap_port' => 993,
            'imap_tls' => true,
            'smtp_host' => 'smtp.work.example.com',
            'smtp_port' => 587,
            'smtp_tls' => 'starttls',
        ], $prefill->pull());
        self::assertSame('account_profile.selected', $events->records[0]['event_type']);
        self::assertSame('work@example.com', $events->records[0]['metadata']['profile']);
        self::assertArrayNotHasKey('password', $events->records[0]['metadata']);
    }

    private function controller(
        SessionMailAccountProfileRepository $repository,
        ?CsrfTokenManager $csrf = null,
        ?AuditEventRepositoryInterface $auditEvents = null,
        ?LoginPrefillStore $prefill = null,
    ): AccountController {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);

        return new AccountController(
            $config,
            new View($root . '/templates', $root . '/public'),
            $csrf ?? new CsrfTokenManager(),
            new CredentialVault($config),
            $repository,
            $this->auditLogger($auditEvents),
            $prefill ?? new LoginPrefillStore(),
        );
    }

    private function login(): MailboxCredentials
    {
        $credentials = new MailboxCredentials(
            'smoke@example.com',
            'secret-password',
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
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . '/mailika-account-audit-' . bin2hex(random_bytes(4)) . '.log';

        return new AuditLogger(
            Config::fromEnvironment(dirname(__DIR__, 2)),
            $events ?? new InMemoryAuditEventRepository(),
        );
    }

    private function config(): Config
    {
        return Config::fromEnvironment(dirname(__DIR__, 2));
    }
}

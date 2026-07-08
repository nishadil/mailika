<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Audit\AuditEventRepositoryInterface;
use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Controller\IdentityController;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Identity\Identity;
use Mailika\Identity\SessionIdentityRepository;
use Mailika\Security\CsrfTokenManager;
use Mailika\Support\View;
use Mailika\Tests\Fixtures\InMemoryAuditEventRepository;
use PHPUnit\Framework\TestCase;

final class IdentityControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('n', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_ENV['APP_KEY'], $_ENV['LOG_PATH']);
    }

    public function testIndexDoesNotRenderDeleteFormForFallbackIdentity(): void
    {
        $controller = $this->controller(new SessionIdentityRepository());
        $this->login();

        $html = $this->send($controller->index(new Request('GET', '/identities', [], [], [], [], [])));

        self::assertStringNotContainsString('action="/identities/delete"', $html);
    }

    public function testIndexRendersDeleteFormForStoredIdentity(): void
    {
        $repository = new SessionIdentityRepository();
        $controller = $this->controller($repository);
        $credentials = $this->login();
        $repository->save($credentials->email, new Identity('Alias', 'alias@example.com'));

        $html = $this->send($controller->index(new Request('GET', '/identities', [], [], [], [], [])));

        self::assertStringContainsString('action="/identities/delete"', $html);
        self::assertStringContainsString('name="id" value="1"', $html);
        self::assertStringContainsString('Delete', $html);
    }

    public function testDeleteRequiresValidCsrfToken(): void
    {
        $repository = new SessionIdentityRepository();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($repository, $csrf);
        $credentials = $this->login();
        $repository->save($credentials->email, new Identity('Alias', 'alias@example.com'));
        $identity = $repository->listForMailbox($credentials->email)[0];

        $controller->delete(new Request('POST', '/identities/delete', [], [
            '_csrf' => 'invalid',
            'id' => (string) $identity->id,
        ], [], [], []));

        self::assertCount(1, $repository->listForMailbox($credentials->email));

        $response = $controller->delete(new Request('POST', '/identities/delete', [], [
            '_csrf' => $csrf->token(),
            'id' => (string) $identity->id,
        ], [], [], []));

        self::assertSame('/identities', $response->headers()['Location']);
        self::assertSame([], $repository->listForMailbox($credentials->email));
    }

    public function testIdentitySaveAndDeleteWriteAuditEvents(): void
    {
        $repository = new SessionIdentityRepository();
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $controller = $this->controller($repository, $csrf, $events);
        $credentials = $this->login();
        $token = $csrf->token();

        $controller->save(new Request('POST', '/identities', [], [
            '_csrf' => $token,
            'display_name' => 'Alias',
            'email' => 'alias@example.com',
            'reply_to' => 'reply@example.com',
            'default' => '1',
        ], [], [], []));
        $identity = $repository->listForMailbox($credentials->email)[0];
        $controller->delete(new Request('POST', '/identities/delete', [], [
            '_csrf' => $token,
            'id' => (string) $identity->id,
        ], [], [], []));

        self::assertSame(['identity.saved', 'identity.deleted'], array_column($events->records, 'event_type'));
        self::assertSame('alias@example.com', $events->records[0]['metadata']['identity']);
        self::assertSame('true', $events->records[0]['metadata']['default']);
        self::assertSame('true', $events->records[0]['metadata']['has_reply_to']);
        self::assertSame('alias@example.com', $events->records[1]['metadata']['identity']);
    }

    private function controller(
        SessionIdentityRepository $repository,
        ?CsrfTokenManager $csrf = null,
        ?AuditEventRepositoryInterface $auditEvents = null,
    ): IdentityController {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);

        return new IdentityController(
            new View($root . '/templates', $root . '/public'),
            $csrf ?? new CsrfTokenManager(),
            new CredentialVault($config),
            $repository,
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
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . '/mailika-identity-audit-' . bin2hex(random_bytes(4)) . '.log';

        return new AuditLogger(
            Config::fromEnvironment(dirname(__DIR__, 2)),
            $events ?? new InMemoryAuditEventRepository(),
        );
    }
}

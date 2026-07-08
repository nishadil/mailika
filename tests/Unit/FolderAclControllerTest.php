<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Audit\AuditEventRepositoryInterface;
use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Controller\FolderAclController;
use Mailika\Http\Request;
use Mailika\Mail\MailboxCapabilities;
use Mailika\Mail\MailboxRights;
use Mailika\Security\CsrfTokenManager;
use Mailika\Tests\Fixtures\InMemoryAuditEventRepository;
use Mailika\Tests\Integration\FakeMailboxClient;
use PHPUnit\Framework\TestCase;

final class FolderAclControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('g', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_ENV['APP_KEY'], $_ENV['LOG_PATH']);
    }

    public function testAdminCanSetAclRights(): void
    {
        $client = $this->aclClient('lra');
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $controller = $this->controller($client, $csrf, $events);

        $response = $controller->save(new Request('POST', '/folders/acl', [], [
            '_csrf' => $csrf->token(),
            'folder' => 'Projects',
            'identifier' => " support@example.com\r\n",
            'rights' => ['r', 'l', 'invalid', 'a'],
        ], [], [], []));

        self::assertSame(['set-acl:Projects:support@example.com:lra'], $client->actions);
        self::assertSame('/mailbox?folder=Projects', $response->headers()['Location']);
        self::assertSame('mail.acl_updated', $events->records[0]['event_type']);
        self::assertSame('support@example.com', $events->records[0]['metadata']['acl_identifier']);
        self::assertSame('lra', $events->records[0]['metadata']['acl_rights']);
    }

    public function testNonAdminCannotSetAclRights(): void
    {
        $client = $this->aclClient('lr');
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($client, $csrf);

        $controller->save(new Request('POST', '/folders/acl', [], [
            '_csrf' => $csrf->token(),
            'folder' => 'Projects',
            'identifier' => 'support@example.com',
            'rights' => ['l', 'r'],
        ], [], [], []));

        self::assertSame([], $client->actions);
    }

    public function testAclUpdateRequiresAtLeastOneValidRight(): void
    {
        $client = $this->aclClient('lra');
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($client, $csrf);

        $controller->save(new Request('POST', '/folders/acl', [], [
            '_csrf' => $csrf->token(),
            'folder' => 'Projects',
            'identifier' => 'support@example.com',
            'rights' => ['not-a-right'],
        ], [], [], []));

        self::assertSame([], $client->actions);
    }

    public function testAdminCanDeleteAclIdentifier(): void
    {
        $client = $this->aclClient('lra');
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $controller = $this->controller($client, $csrf, $events);

        $response = $controller->delete(new Request('POST', '/folders/acl/delete', [], [
            '_csrf' => $csrf->token(),
            'folder' => 'Projects',
            'identifier' => 'support@example.com',
        ], [], [], []));

        self::assertSame(['delete-acl:Projects:support@example.com'], $client->actions);
        self::assertSame('/mailbox?folder=Projects', $response->headers()['Location']);
        self::assertSame('mail.acl_deleted', $events->records[0]['event_type']);
        self::assertSame('support@example.com', $events->records[0]['metadata']['acl_identifier']);
    }

    private function aclClient(string $rights): FakeMailboxClient
    {
        $client = new FakeMailboxClient();
        $client->capabilitiesFixture = new MailboxCapabilities(acl: true);
        $client->rightsFixture = new MailboxRights($rights);

        return $client;
    }

    private function controller(
        FakeMailboxClient $client,
        CsrfTokenManager $csrf,
        ?AuditEventRepositoryInterface $events = null,
    ): FolderAclController {
        $config = Config::fromEnvironment(dirname(__DIR__, 2));
        (new CredentialVault($config))->store($this->credentials());

        return new FolderAclController($csrf, new CredentialVault($config), $client, $this->auditLogger($events));
    }

    private function credentials(): MailboxCredentials
    {
        return new MailboxCredentials(
            'smoke@example.com',
            'secret',
            'imap.example.com',
            993,
            true,
            'smtp.example.com',
            587,
            'starttls',
        );
    }

    private function auditLogger(?AuditEventRepositoryInterface $events = null): AuditLogger
    {
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . '/mailika-folder-acl-audit-' . bin2hex(random_bytes(4)) . '.log';

        return new AuditLogger(
            Config::fromEnvironment(dirname(__DIR__, 2)),
            $events ?? new InMemoryAuditEventRepository(),
        );
    }
}

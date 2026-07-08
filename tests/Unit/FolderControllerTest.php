<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Audit\AuditEventRepositoryInterface;
use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Controller\FolderController;
use Mailika\Http\Request;
use Mailika\Mail\MailboxCapabilities;
use Mailika\Mail\MailboxFolder;
use Mailika\Mail\MailboxRights;
use Mailika\Security\CsrfTokenManager;
use Mailika\Tests\Fixtures\InMemoryAuditEventRepository;
use Mailika\Tests\Integration\FakeMailboxClient;
use PHPUnit\Framework\TestCase;

final class FolderControllerTest extends TestCase
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

    public function testCreateFolderNormalizesNameAndRedirectsToNewFolder(): void
    {
        $client = new FakeMailboxClient();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($client, $csrf);

        $response = $controller->create(new Request('POST', '/folders/create', [], [
            '_csrf' => $csrf->token(),
            'name' => ' Projects/2026 ',
        ], [], [], []));

        self::assertSame(['create:Projects/2026'], $client->actions);
        self::assertSame('/mailbox?folder=Projects%2F2026', $response->headers()['Location']);
    }

    public function testCreateFolderRejectsControlCharacters(): void
    {
        $client = new FakeMailboxClient();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($client, $csrf);

        $response = $controller->create(new Request('POST', '/folders/create', [], [
            '_csrf' => $csrf->token(),
            'name' => "Bad\r\nName",
        ], [], [], []));

        self::assertSame([], $client->actions);
        self::assertSame('/mailbox', $response->headers()['Location']);
    }

    public function testRenameAndDeleteRegularFolder(): void
    {
        $client = new FakeMailboxClient();
        $client->folderFixtures = [
            new MailboxFolder('INBOX', 'Inbox', specialUse: ['inbox']),
            new MailboxFolder('Projects', 'Projects'),
        ];
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($client, $csrf);
        $token = $csrf->token();

        $rename = $controller->rename(new Request('POST', '/folders/rename', [], [
            '_csrf' => $token,
            'folder' => 'Projects',
            'new_name' => 'Clients',
        ], [], [], []));
        $delete = $controller->delete(new Request('POST', '/folders/delete', [], [
            '_csrf' => $token,
            'folder' => 'Projects',
        ], [], [], []));

        self::assertSame(['rename:Projects:Clients', 'delete:Projects'], $client->actions);
        self::assertSame('/mailbox?folder=Clients', $rename->headers()['Location']);
        self::assertSame('/mailbox', $delete->headers()['Location']);
    }

    public function testFolderMutationsWriteAuditEvents(): void
    {
        $client = new FakeMailboxClient();
        $client->folderFixtures = [
            new MailboxFolder('INBOX', 'Inbox', specialUse: ['inbox']),
            new MailboxFolder('Projects', 'Projects'),
        ];
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $controller = $this->controller($client, $csrf, $events);
        $token = $csrf->token();

        $controller->create(new Request('POST', '/folders/create', [], [
            '_csrf' => $token,
            'name' => 'Clients',
        ], [], [], []));
        $controller->rename(new Request('POST', '/folders/rename', [], [
            '_csrf' => $token,
            'folder' => 'Projects',
            'new_name' => 'Archive/Projects',
        ], [], [], []));
        $controller->delete(new Request('POST', '/folders/delete', [], [
            '_csrf' => $token,
            'folder' => 'Projects',
        ], [], [], []));

        self::assertSame(
            ['mail.folder_created', 'mail.folder_renamed', 'mail.folder_deleted'],
            array_column($events->records, 'event_type'),
        );
        self::assertSame('Clients', $events->records[0]['metadata']['folder']);
        self::assertSame('Archive/Projects', $events->records[1]['metadata']['target_folder']);
    }

    public function testFolderMutationFailuresRedirectSafelyAndWriteAuditEvents(): void
    {
        $client = new FakeMailboxClient();
        $client->throwOnActions = ['create', 'rename', 'delete'];
        $client->folderFixtures = [
            new MailboxFolder('INBOX', 'Inbox', specialUse: ['inbox']),
            new MailboxFolder('Projects', 'Projects'),
        ];
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $controller = $this->controller($client, $csrf, $events);
        $token = $csrf->token();

        $create = $controller->create(new Request('POST', '/folders/create', [], [
            '_csrf' => $token,
            'name' => 'Clients',
        ], [], [], []));
        $rename = $controller->rename(new Request('POST', '/folders/rename', [], [
            '_csrf' => $token,
            'folder' => 'Projects',
            'new_name' => 'Archive/Projects',
        ], [], [], []));
        $delete = $controller->delete(new Request('POST', '/folders/delete', [], [
            '_csrf' => $token,
            'folder' => 'Projects',
        ], [], [], []));

        self::assertSame('/mailbox', $create->headers()['Location']);
        self::assertSame('/mailbox?folder=Projects', $rename->headers()['Location']);
        self::assertSame('/mailbox', $delete->headers()['Location']);
        self::assertSame(
            ['mail.folder_create_failed', 'mail.folder_rename_failed', 'mail.folder_delete_failed'],
            array_column($events->records, 'event_type'),
        );
    }

    public function testProtectsInboxAndSpecialUseFolders(): void
    {
        $client = new FakeMailboxClient();
        $client->folderFixtures = [
            new MailboxFolder('INBOX', 'Inbox', specialUse: ['inbox']),
            new MailboxFolder('Sent', 'Sent', specialUse: ['sent']),
        ];
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($client, $csrf);
        $token = $csrf->token();

        $controller->rename(new Request('POST', '/folders/rename', [], [
            '_csrf' => $token,
            'folder' => 'INBOX',
            'new_name' => 'Inbox2',
        ], [], [], []));
        $controller->delete(new Request('POST', '/folders/delete', [], [
            '_csrf' => $token,
            'folder' => 'Sent',
        ], [], [], []));

        self::assertSame([], $client->actions);
    }

    public function testReportedAclRightsBlockUnsupportedFolderMutations(): void
    {
        $client = new FakeMailboxClient();
        $client->capabilitiesFixture = new MailboxCapabilities(acl: true);
        $client->rightsFixture = new MailboxRights('lr');
        $client->folderFixtures = [
            new MailboxFolder('INBOX', 'Inbox', specialUse: ['inbox']),
            new MailboxFolder('Projects', 'Projects'),
        ];
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($client, $csrf);
        $token = $csrf->token();

        $controller->create(new Request('POST', '/folders/create', [], [
            '_csrf' => $token,
            'name' => 'Clients',
        ], [], [], []));
        $controller->rename(new Request('POST', '/folders/rename', [], [
            '_csrf' => $token,
            'folder' => 'Projects',
            'new_name' => 'Clients',
        ], [], [], []));
        $controller->delete(new Request('POST', '/folders/delete', [], [
            '_csrf' => $token,
            'folder' => 'Projects',
        ], [], [], []));

        self::assertSame([], $client->actions);
    }

    private function controller(
        FakeMailboxClient $client,
        CsrfTokenManager $csrf,
        ?AuditEventRepositoryInterface $events = null,
    ): FolderController {
        $config = Config::fromEnvironment(dirname(__DIR__, 2));
        (new CredentialVault($config))->store($this->credentials());

        return new FolderController($csrf, new CredentialVault($config), $client, $this->auditLogger($events));
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
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . '/mailika-folder-audit-' . bin2hex(random_bytes(4)) . '.log';

        return new AuditLogger(Config::fromEnvironment(dirname(__DIR__, 2)), $events ?? $this->events());
    }

    private function events(): AuditEventRepositoryInterface
    {
        return new InMemoryAuditEventRepository();
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Audit\AuditEventRepositoryInterface;
use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Controller\MessageActionController;
use Mailika\Http\Request;
use Mailika\Mail\MailboxCapabilities;
use Mailika\Mail\MailboxFolder;
use Mailika\Mail\MailboxRights;
use Mailika\Mail\MessageEnvelope;
use Mailika\Mail\MessageAttachment;
use Mailika\Mail\SessionMessageMetadataCacheRepository;
use Mailika\Security\AttachmentPolicy;
use Mailika\Security\CsrfTokenManager;
use Mailika\Tests\Integration\FakeMailboxClient;
use PHPUnit\Framework\TestCase;

final class MessageActionControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('a', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_ENV['APP_KEY'], $_ENV['LOG_PATH'], $_ENV['MAILIKA_MAX_ATTACHMENT_BYTES']);
    }

    public function testAttachmentDownloadSanitizesServerProvidedHeaders(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $client = new FakeMailboxClient();
        $client->attachment = new MessageAttachment(
            'att-1',
            "../../secret\r\nSet-Cookie: x=y.txt",
            "text/html\r\nX-Evil: 1",
            7,
            'payload',
        );

        (new CredentialVault($config))->store($this->credentials());

        $controller = new MessageActionController(
            new CsrfTokenManager(),
            new CredentialVault($config),
            $client,
            new AttachmentPolicy($config),
            $this->auditLogger(),
        );

        $response = $controller->attachment(new Request('GET', '/attachment', [
            'folder' => 'INBOX',
            'message' => '42',
            'attachment' => 'att-1',
        ], [], [], [], []));
        $headers = $response->headers();

        self::assertSame('application/octet-stream', $headers['Content-Type']);
        self::assertSame(
            'attachment; filename="secret__Set-Cookie_ x_y.txt"',
            $headers['Content-Disposition'],
        );
        self::assertSame('no-store', $headers['Cache-Control']);
        self::assertSame('no-cache', $headers['Pragma']);
        self::assertSame('0', $headers['Expires']);
        self::assertSame('noopen', $headers['X-Download-Options']);
        self::assertSame(
            "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; sandbox",
            $headers['Content-Security-Policy'],
        );
        self::assertStringNotContainsString("\r", implode("\n", $headers));
        self::assertStringNotContainsString("\nX-Evil", implode("\n", $headers));
        self::assertStringNotContainsString('..', $headers['Content-Disposition']);
    }

    public function testBulkMessageActionAppliesUniqueSelectedIdsAndPreservesContext(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $client = new FakeMailboxClient();
        $csrf = new CsrfTokenManager();
        $token = $csrf->token();

        (new CredentialVault($config))->store($this->credentials());

        $controller = new MessageActionController(
            $csrf,
            new CredentialVault($config),
            $client,
            new AttachmentPolicy($config),
            $this->auditLogger(),
        );

        $response = $controller->update(new Request('POST', '/message/action', [], [
            '_csrf' => $token,
            'folder' => 'INBOX',
            'q' => 'invoice',
            'page' => '3',
            'selected_ids' => ['1', '2', '1', '', "bad\r\nid"],
            'action' => 'flag',
        ], [], [], []));

        self::assertSame(['flagged', 'flagged'], $client->actions);
        self::assertSame('/mailbox?folder=INBOX&q=invoice&page=3', $response->headers()['Location']);
    }

    public function testInvalidSingleMessageIdDoesNotReachMailboxClient(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $client = new FakeMailboxClient();
        $csrf = new CsrfTokenManager();

        (new CredentialVault($config))->store($this->credentials());

        $controller = new MessageActionController(
            $csrf,
            new CredentialVault($config),
            $client,
            new AttachmentPolicy($config),
            $this->auditLogger(),
        );

        $response = $controller->update(new Request('POST', '/message/action', [], [
            '_csrf' => $csrf->token(),
            'folder' => 'INBOX',
            'id' => "1\r\n2",
            'action' => 'delete',
        ], [], [], []));

        self::assertSame([], $client->actions);
        self::assertSame('/mailbox?folder=INBOX', $response->headers()['Location']);
    }

    public function testInlineDownloadRequiresInlineImagePart(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $client = new FakeMailboxClient();
        $client->attachment = new MessageAttachment('logo', 'logo.png', 'image/png', 4, 'test', true);

        (new CredentialVault($config))->store($this->credentials());

        $controller = new MessageActionController(
            new CsrfTokenManager(),
            new CredentialVault($config),
            $client,
            new AttachmentPolicy($config),
            $this->auditLogger(),
        );

        $response = $controller->attachment(new Request('GET', '/attachment', [
            'folder' => 'INBOX',
            'message' => '42',
            'attachment' => 'logo',
            'inline' => '1',
        ], [], [], [], []));

        self::assertSame('inline; filename="logo.png"', $response->headers()['Content-Disposition']);
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertSame('no-cache', $response->headers()['Pragma']);
        self::assertSame('0', $response->headers()['Expires']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
        self::assertSame('noopen', $response->headers()['X-Download-Options']);
    }

    public function testAttachmentDownloadRejectsMalformedIdsBeforeMailboxLookup(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $client = new FakeMailboxClient();
        $client->attachment = new MessageAttachment('logo', 'logo.png', 'image/png', 4, 'test', true);

        (new CredentialVault($config))->store($this->credentials());

        $controller = new MessageActionController(
            new CsrfTokenManager(),
            new CredentialVault($config),
            $client,
            new AttachmentPolicy($config),
            $this->auditLogger(),
        );

        $response = $controller->attachment(new Request('GET', '/attachment', [
            'folder' => 'INBOX',
            'message' => "42\r\nX-Injected: 1",
            'attachment' => 'logo',
        ], [], [], [], []));

        self::assertSame(404, $response->status());
        self::assertSame('Attachment not found.', $response->content());
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertSame(
            "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; sandbox",
            $response->headers()['Content-Security-Policy'],
        );
    }

    public function testSvgAttachmentIsNotRenderedInline(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $client = new FakeMailboxClient();
        $client->attachment = new MessageAttachment('logo', 'logo.svg', 'image/svg+xml', 11, '<svg></svg>', true);

        (new CredentialVault($config))->store($this->credentials());

        $controller = new MessageActionController(
            new CsrfTokenManager(),
            new CredentialVault($config),
            $client,
            new AttachmentPolicy($config),
            $this->auditLogger(),
        );

        $response = $controller->attachment(new Request('GET', '/attachment', [
            'folder' => 'INBOX',
            'message' => '42',
            'attachment' => 'logo',
            'inline' => '1',
        ], [], [], [], []));

        self::assertSame('application/octet-stream', $response->headers()['Content-Type']);
        self::assertSame('attachment; filename="logo.svg"', $response->headers()['Content-Disposition']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
    }

    public function testHtmlAttachmentIsForcedToGenericDownloadContentType(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $client = new FakeMailboxClient();
        $client->attachment = new MessageAttachment('html', 'invoice.html', 'text/html', 11, '<b>test</b>');

        (new CredentialVault($config))->store($this->credentials());

        $controller = new MessageActionController(
            new CsrfTokenManager(),
            new CredentialVault($config),
            $client,
            new AttachmentPolicy($config),
            $this->auditLogger(),
        );

        $response = $controller->attachment(new Request('GET', '/attachment', [
            'folder' => 'INBOX',
            'message' => '42',
            'attachment' => 'html',
        ], [], [], [], []));

        self::assertSame('application/octet-stream', $response->headers()['Content-Type']);
        self::assertSame('attachment; filename="invoice.html"', $response->headers()['Content-Disposition']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
    }

    public function testOversizedAttachmentDownloadIsBlockedAndAudited(): void
    {
        $_ENV['MAILIKA_MAX_ATTACHMENT_BYTES'] = '3';
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $client = new FakeMailboxClient();
        $client->attachment = new MessageAttachment('large', 'large.bin', 'application/octet-stream', 2, 'payload');
        $events = new class implements AuditEventRepositoryInterface {
            /**
             * @var list<array<string, mixed>>
             */
            public array $records = [];

            /**
             * @param array<string, scalar|null> $metadata
             */
            public function record(
                string $eventType,
                ?string $mailboxIdentity,
                ?string $ipAddress,
                ?string $userAgent,
                array $metadata,
            ): void {
                $this->records[] = [
                    'event_type' => $eventType,
                    'mailbox' => $mailboxIdentity,
                    'metadata' => $metadata,
                ];
            }
        };

        (new CredentialVault($config))->store($this->credentials());

        $controller = new MessageActionController(
            new CsrfTokenManager(),
            new CredentialVault($config),
            $client,
            new AttachmentPolicy($config),
            $this->auditLogger($events),
        );

        $response = $controller->attachment(new Request('GET', '/attachment', [
            'folder' => 'INBOX',
            'message' => '42',
            'attachment' => 'large',
        ], [], [], [], []));

        self::assertSame(413, $response->status());
        self::assertSame('Attachment exceeds the configured size limit.', $response->content());
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertSame('no-cache', $response->headers()['Pragma']);
        self::assertSame('0', $response->headers()['Expires']);
        self::assertSame('noopen', $response->headers()['X-Download-Options']);
        self::assertSame(
            "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; sandbox",
            $response->headers()['Content-Security-Policy'],
        );
        self::assertCount(1, $events->records);
        self::assertSame('mail.attachment_blocked', $events->records[0]['event_type']);
        self::assertSame('size_limit', $events->records[0]['metadata']['reason']);
        self::assertArrayNotHasKey('filename', $events->records[0]['metadata']);
    }

    public function testMoveActionRequiresSelectableTargetFolder(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $client = new FakeMailboxClient();
        $client->folderFixtures = [
            new MailboxFolder('INBOX', 'Inbox'),
            new MailboxFolder('Archive', 'Archive'),
            new MailboxFolder('Virtual', 'Virtual', selectable: false),
        ];
        $csrf = new CsrfTokenManager();
        $token = $csrf->token();

        (new CredentialVault($config))->store($this->credentials());

        $controller = new MessageActionController(
            $csrf,
            new CredentialVault($config),
            $client,
            new AttachmentPolicy($config),
            $this->auditLogger(),
        );

        $controller->update(new Request('POST', '/message/action', [], [
            '_csrf' => $token,
            'folder' => 'INBOX',
            'id' => '1',
            'target_folder' => 'NotARealFolder',
            'action' => 'move',
        ], [], [], []));

        $controller->update(new Request('POST', '/message/action', [], [
            '_csrf' => $token,
            'folder' => 'INBOX',
            'id' => '1',
            'target_folder' => 'Virtual',
            'action' => 'move',
        ], [], [], []));

        $controller->update(new Request('POST', '/message/action', [], [
            '_csrf' => $token,
            'folder' => 'INBOX',
            'id' => '1',
            'target_folder' => 'Archive',
            'action' => 'move',
        ], [], [], []));

        self::assertSame(['move'], $client->actions);
    }

    public function testMessageActionsWriteAggregateAuditEvent(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $client = new FakeMailboxClient();
        $client->folderFixtures = [
            new MailboxFolder('INBOX', 'Inbox'),
            new MailboxFolder('Archive', 'Archive'),
        ];
        $csrf = new CsrfTokenManager();
        $token = $csrf->token();
        $events = new class implements AuditEventRepositoryInterface {
            /**
             * @var list<array<string, mixed>>
             */
            public array $records = [];

            /**
             * @param array<string, scalar|null> $metadata
             */
            public function record(
                string $eventType,
                ?string $mailboxIdentity,
                ?string $ipAddress,
                ?string $userAgent,
                array $metadata,
            ): void {
                $this->records[] = [
                    'event_type' => $eventType,
                    'mailbox' => $mailboxIdentity,
                    'metadata' => $metadata,
                ];
            }
        };

        (new CredentialVault($config))->store($this->credentials());

        $controller = new MessageActionController(
            $csrf,
            new CredentialVault($config),
            $client,
            new AttachmentPolicy($config),
            $this->auditLogger($events),
        );

        $controller->update(new Request('POST', '/message/action', [], [
            '_csrf' => $token,
            'folder' => 'INBOX',
            'selected_ids' => ['1', '2'],
            'target_folder' => 'Archive',
            'action' => 'move',
        ], [], [], []));

        self::assertCount(1, $events->records);
        self::assertSame('mail.message_action', $events->records[0]['event_type']);
        self::assertSame('smoke@example.com', $events->records[0]['mailbox']);
        self::assertSame('move', $events->records[0]['metadata']['action']);
        self::assertSame('INBOX', $events->records[0]['metadata']['folder']);
        self::assertSame('Archive', $events->records[0]['metadata']['target_folder']);
        self::assertSame('2', $events->records[0]['metadata']['count']);
        self::assertSame('2', $events->records[0]['metadata']['succeeded']);
    }

    public function testReportedAclRightsBlockUnsupportedMessageMutation(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $client = new FakeMailboxClient();
        $client->capabilitiesFixture = new MailboxCapabilities(acl: true);
        $client->rightsFixture = new MailboxRights('lr');
        $csrf = new CsrfTokenManager();
        $token = $csrf->token();

        (new CredentialVault($config))->store($this->credentials());

        $controller = new MessageActionController(
            $csrf,
            new CredentialVault($config),
            $client,
            new AttachmentPolicy($config),
            $this->auditLogger(),
        );

        $response = $controller->update(new Request('POST', '/message/action', [], [
            '_csrf' => $token,
            'folder' => 'INBOX',
            'id' => '1',
            'action' => 'flag',
        ], [], [], []));

        self::assertSame([], $client->actions);
        self::assertSame('/mailbox?folder=INBOX', $response->headers()['Location']);
    }

    public function testSuccessfulMessageActionsUpdateCachedFallbackMetadata(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $client = new FakeMailboxClient();
        $client->folderFixtures = [
            new MailboxFolder('INBOX', 'Inbox'),
            new MailboxFolder('Archive', 'Archive'),
        ];
        $cache = new SessionMessageMetadataCacheRepository();
        $cache->store('smoke@example.com', 'INBOX', [
            new MessageEnvelope('1', 'sender@example.com', 'Hello', 'today', false, false),
            new MessageEnvelope('2', 'sender@example.com', 'Delete me', 'today', true, false),
        ]);
        $csrf = new CsrfTokenManager();
        $token = $csrf->token();

        (new CredentialVault($config))->store($this->credentials());

        $controller = new MessageActionController(
            $csrf,
            new CredentialVault($config),
            $client,
            new AttachmentPolicy($config),
            $this->auditLogger(),
            $cache,
        );

        $controller->update(new Request('POST', '/message/action', [], [
            '_csrf' => $token,
            'folder' => 'INBOX',
            'id' => '1',
            'action' => 'flag',
        ], [], [], []));

        self::assertTrue($cache->list('smoke@example.com', 'INBOX', 50, 1, 'Hello')[0]->flagged);

        $controller->update(new Request('POST', '/message/action', [], [
            '_csrf' => $token,
            'folder' => 'INBOX',
            'id' => '1',
            'target_folder' => 'Archive',
            'action' => 'move',
        ], [], [], []));

        self::assertSame([], $cache->list('smoke@example.com', 'INBOX', 50, 1, 'Hello'));
        self::assertSame('1', $cache->list('smoke@example.com', 'Archive')[0]->id);

        $controller->update(new Request('POST', '/message/action', [], [
            '_csrf' => $token,
            'folder' => 'INBOX',
            'id' => '2',
            'action' => 'delete',
        ], [], [], []));

        self::assertSame([], $cache->list('smoke@example.com', 'INBOX', 50, 1, 'Delete me'));
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
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . '/mailika-message-action-audit-'
            . bin2hex(random_bytes(4)) . '.log';
        $events ??= new class implements AuditEventRepositoryInterface {
            /**
             * @param array<string, scalar|null> $metadata
             */
            public function record(
                string $eventType,
                ?string $mailboxIdentity,
                ?string $ipAddress,
                ?string $userAgent,
                array $metadata,
            ): void {
            }
        };

        return new AuditLogger(Config::fromEnvironment(dirname(__DIR__, 2)), $events);
    }
}

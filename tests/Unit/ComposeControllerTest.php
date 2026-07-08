<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Audit\AuditEventRepositoryInterface;
use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Contact\Contact;
use Mailika\Contact\SessionContactRepository;
use Mailika\Controller\ComposeController;
use Mailika\Crypto\OpenPgpLeakGuard;
use Mailika\Draft\DraftMessage;
use Mailika\Draft\SessionDraftRepository;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Identity\Identity;
use Mailika\Identity\SessionIdentityRepository;
use Mailika\Mail\Message;
use Mailika\Mail\SendEnvelope;
use Mailika\Mail\SmtpSenderInterface;
use Mailika\Security\AttachmentPolicy;
use Mailika\Security\CsrfTokenManager;
use Mailika\Support\View;
use Mailika\Tests\Integration\FakeMailboxClient;
use PHPUnit\Framework\TestCase;

final class ComposeControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('i', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_ENV['APP_KEY'], $_ENV['LOG_PATH']);
    }

    public function testSendUsesOnlyConfiguredIdentityAndReplyTo(): void
    {
        $sender = $this->sender();
        $identities = new SessionIdentityRepository();
        $controller = $this->controller($sender, $identities);
        $credentials = $this->login();
        $identities->save(
            $credentials->email,
            new Identity('Alias', 'alias@example.com', 'reply@example.com', true),
        );
        $token = (new CsrfTokenManager())->token();

        $controller->send($this->composeRequest($token, 'alias@example.com'));

        self::assertNotNull($sender->envelope);
        self::assertSame('alias@example.com', $sender->envelope->identityEmail);
        self::assertSame('reply@example.com', $sender->envelope->replyToEmail);
    }

    public function testSendRejectsUnconfiguredPostedIdentity(): void
    {
        $sender = $this->sender();
        $identities = new SessionIdentityRepository();
        $controller = $this->controller($sender, $identities);
        $this->login();
        $identities->save('smoke@example.com', new Identity('Alias', 'alias@example.com'));
        $token = (new CsrfTokenManager())->token();

        $response = $controller->send($this->composeRequest($token, 'attacker@example.com'));
        $content = $this->send($response);

        self::assertNull($sender->envelope);
        self::assertStringContainsString('Selected sender identity is not configured.', $content);
    }

    public function testSendNormalizesIdnaRecipientsAndMarksSmtpUtf8(): void
    {
        $sender = $this->sender();
        $identities = new SessionIdentityRepository();
        $controller = $this->controller($sender, $identities);
        $this->login();
        $token = (new CsrfTokenManager())->token();

        $controller->send($this->composeRequest($token, '', 'büro@bücher.example', 'copy@bücher.example'));

        self::assertNotNull($sender->envelope);
        self::assertSame(['büro@xn--bcher-kva.example'], $sender->envelope->to);
        self::assertSame(['copy@xn--bcher-kva.example'], $sender->envelope->cc);
        self::assertTrue($sender->envelope->requiresSmtpUtf8);
    }

    public function testShowRendersContactRecipientSuggestions(): void
    {
        $sender = $this->sender();
        $identities = new SessionIdentityRepository();
        $contacts = new SessionContactRepository();
        $controller = $this->controller($sender, $identities, $contacts);
        $credentials = $this->login();
        $contacts->save($credentials->email, new Contact('Alice Example', 'alice@example.com'));

        $html = $this->send($controller->show(new Request('GET', '/compose', [], [], [], [], [])));

        self::assertStringContainsString('data-recipient-suggestion', $html);
        self::assertStringContainsString('data-email="alice@example.com"', $html);
        self::assertStringContainsString('Alice Example', $html);
    }

    public function testReplyAllPrefillsReplyToCcAndSourceMessageId(): void
    {
        $sender = $this->sender();
        $identities = new SessionIdentityRepository();
        $mailboxClient = new FakeMailboxClient();
        $mailboxClient->message = new Message(
            '42',
            'Sender <sender@example.com>',
            'Smoke <smoke@example.com>, Team <team@example.com>',
            'Project',
            'Tue, 7 Jul 2026 10:00:00 +0000',
            '<p>Hello</p>',
            'Hello',
            [],
            'Copy <copy@example.com>, Smoke <smoke@example.com>',
            'Replies <reply@example.com>',
            'source-message@example',
        );
        $controller = $this->controller($sender, $identities, mailboxClient: $mailboxClient);
        $this->login();

        $html = $this->send($controller->show(new Request('GET', '/compose', [
            'mode' => 'reply-all',
            'folder' => 'INBOX',
            'id' => '42',
        ], [], [], [], [])));

        self::assertStringContainsString('value="reply@example.com"', $html);
        self::assertStringContainsString('value="team@example.com, copy@example.com"', $html);
        self::assertStringContainsString('value="Re: Project"', $html);
        self::assertStringContainsString('name="reply_to_message_id" value="source-message@example"', $html);
    }

    public function testSendCarriesReplySourceMessageId(): void
    {
        $sender = $this->sender();
        $identities = new SessionIdentityRepository();
        $controller = $this->controller($sender, $identities);
        $this->login();
        $token = (new CsrfTokenManager())->token();

        $controller->send($this->composeRequest($token, '', replyToMessageId: 'source-message@example'));

        self::assertNotNull($sender->envelope);
        self::assertSame('source-message@example', $sender->envelope->replyToMessageId);
    }

    public function testShowPrefillsDraftAndKeepsDraftId(): void
    {
        $sender = $this->sender();
        $identities = new SessionIdentityRepository();
        $drafts = new SessionDraftRepository();
        $controller = $this->controller($sender, $identities, drafts: $drafts);
        $credentials = $this->login();
        $identities->save($credentials->email, new Identity('Alias', 'alias@example.com'));
        $drafts->save(
            $credentials->email,
            new DraftMessage('', 'to@example.com', 'cc@example.com', '', 'Draft', 'Body', '', 'alias@example.com'),
        );
        $draft = $drafts->listForMailbox($credentials->email)[0];

        $html = $this->send($controller->show(new Request('GET', '/compose', [
            'draft' => $draft->id,
        ], [], [], [], [])));

        self::assertStringContainsString('name="draft_id" value="' . $draft->id . '"', $html);
        self::assertStringContainsString('value="to@example.com"', $html);
        self::assertStringContainsString('value="cc@example.com"', $html);
        self::assertStringContainsString('value="Draft"', $html);
        self::assertStringContainsString('value="alias@example.com" selected', $html);
    }

    public function testSavingExistingDraftUpdatesInsteadOfDuplicating(): void
    {
        $sender = $this->sender();
        $identities = new SessionIdentityRepository();
        $drafts = new SessionDraftRepository();
        $controller = $this->controller($sender, $identities, drafts: $drafts);
        $credentials = $this->login();
        $identities->save($credentials->email, new Identity('Alias', 'alias@example.com'));
        $drafts->save($credentials->email, new DraftMessage('', 'old@example.com', '', '', 'Old', 'Old body', ''));
        $draft = $drafts->listForMailbox($credentials->email)[0];
        $token = (new CsrfTokenManager())->token();

        $response = $controller->send(new Request('POST', '/compose', [], [
            '_csrf' => $token,
            'intent' => 'draft',
            'draft_id' => $draft->id,
            'to' => 'new@example.com',
            'cc' => '',
            'bcc' => '',
            'subject' => 'New',
            'body' => 'New body',
            'identity' => 'alias@example.com',
            'reply_to_message_id' => '',
        ], [], [], []));
        $updated = $drafts->listForMailbox($credentials->email);

        self::assertSame('/drafts', $response->headers()['Location']);
        self::assertCount(1, $updated);
        self::assertSame($draft->id, $updated[0]->id);
        self::assertSame('new@example.com', $updated[0]->to);
        self::assertSame('New', $updated[0]->subject);
        self::assertSame('New body', $updated[0]->body);
        self::assertSame('alias@example.com', $updated[0]->identityEmail);
    }

    public function testSendingExistingDraftRemovesItAfterDelivery(): void
    {
        $sender = $this->sender();
        $identities = new SessionIdentityRepository();
        $drafts = new SessionDraftRepository();
        $controller = $this->controller($sender, $identities, drafts: $drafts);
        $credentials = $this->login();
        $drafts->save($credentials->email, new DraftMessage('', 'to@example.com', '', '', 'Draft', 'Body', ''));
        $draft = $drafts->listForMailbox($credentials->email)[0];
        $token = (new CsrfTokenManager())->token();

        $response = $controller->send(new Request('POST', '/compose', [], [
            '_csrf' => $token,
            'draft_id' => $draft->id,
            'to' => 'to@example.com',
            'cc' => '',
            'bcc' => '',
            'subject' => 'Draft',
            'body' => 'Body',
            'identity' => '',
            'reply_to_message_id' => '',
        ], [], [], []));

        self::assertSame('/mailbox', $response->headers()['Location']);
        self::assertSame([], $drafts->listForMailbox($credentials->email));
    }

    public function testSendRejectsOpenPgpPrivateKeyMaterialInBody(): void
    {
        $sender = $this->sender();
        $identities = new SessionIdentityRepository();
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
                $this->records[] = ['event_type' => $eventType, 'metadata' => $metadata];
            }
        };
        $controller = $this->controller($sender, $identities, auditEvents: $events);
        $this->login();
        $token = (new CsrfTokenManager())->token();

        $response = $controller->send(new Request('POST', '/compose', [], [
            '_csrf' => $token,
            'to' => 'to@example.com',
            'cc' => '',
            'bcc' => '',
            'subject' => 'Subject',
            'body' => "-----BEGIN PGP PRIVATE KEY BLOCK-----\nsecret\n-----END PGP PRIVATE KEY BLOCK-----",
            'identity' => '',
            'reply_to_message_id' => '',
        ], [], [], []));
        $html = $this->send($response);

        self::assertNull($sender->envelope);
        self::assertStringContainsString('OpenPGP private key material cannot be sent or saved.', $html);
        self::assertSame('mail.private_key_blocked', $events->records[0]['event_type']);
        self::assertSame('send', $events->records[0]['metadata']['intent']);
    }

    public function testDraftRejectsOpenPgpPrivateKeyMaterialInBody(): void
    {
        $sender = $this->sender();
        $identities = new SessionIdentityRepository();
        $drafts = new SessionDraftRepository();
        $controller = $this->controller($sender, $identities, drafts: $drafts);
        $credentials = $this->login();
        $token = (new CsrfTokenManager())->token();

        $response = $controller->send(new Request('POST', '/compose', [], [
            '_csrf' => $token,
            'intent' => 'draft',
            'draft_id' => '',
            'to' => 'to@example.com',
            'cc' => '',
            'bcc' => '',
            'subject' => 'Subject',
            'body' => "-----BEGIN PGP PRIVATE KEY BLOCK-----\nsecret\n-----END PGP PRIVATE KEY BLOCK-----",
            'identity' => '',
            'reply_to_message_id' => '',
        ], [], [], []));

        self::assertSame(422, $response->status());
        self::assertSame([], $drafts->listForMailbox($credentials->email));
    }

    public function testComposeWritesAggregateAuditEventsWithoutMessageContent(): void
    {
        $sender = $this->sender();
        $identities = new SessionIdentityRepository();
        $drafts = new SessionDraftRepository();
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
        $controller = $this->controller($sender, $identities, drafts: $drafts, auditEvents: $events);
        $credentials = $this->login();
        $identities->save($credentials->email, new Identity('Alias', 'alias@example.com', null, true));
        $token = (new CsrfTokenManager())->token();

        $controller->send(new Request('POST', '/compose', [], [
            '_csrf' => $token,
            'intent' => 'draft',
            'draft_id' => '',
            'to' => 'secret-recipient@example.com',
            'cc' => '',
            'bcc' => '',
            'subject' => 'Sensitive subject',
            'body' => 'Sensitive body',
            'identity' => 'alias@example.com',
            'reply_to_message_id' => '',
        ], [], [], []));
        $controller->send($this->composeRequest($token, 'alias@example.com', 'to@example.com', 'cc@example.com'));

        self::assertCount(2, $events->records);
        self::assertSame('mail.draft_saved', $events->records[0]['event_type']);
        self::assertSame('mail.sent', $events->records[1]['event_type']);
        self::assertSame('smoke@example.com', $events->records[1]['mailbox']);
        self::assertSame('alias@example.com', $events->records[1]['metadata']['identity']);
        self::assertSame('1', $events->records[1]['metadata']['to_count']);
        self::assertSame('1', $events->records[1]['metadata']['cc_count']);
        self::assertArrayNotHasKey('subject', $events->records[1]['metadata']);
        self::assertArrayNotHasKey('body', $events->records[1]['metadata']);
    }

    /**
     * @param SmtpSenderInterface&object{envelope:?SendEnvelope} $sender
     */
    private function controller(
        SmtpSenderInterface $sender,
        SessionIdentityRepository $identities,
        ?SessionContactRepository $contacts = null,
        ?FakeMailboxClient $mailboxClient = null,
        ?SessionDraftRepository $drafts = null,
        ?AuditEventRepositoryInterface $auditEvents = null,
    ): ComposeController {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);

        return new ComposeController(
            new View($root . '/templates', $root . '/public'),
            new CsrfTokenManager(),
            new CredentialVault($config),
            $sender,
            $mailboxClient ?? new FakeMailboxClient(),
            new AttachmentPolicy($config),
            $contacts ?? new SessionContactRepository(),
            $identities,
            $drafts ?? new SessionDraftRepository(),
            new OpenPgpLeakGuard(),
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

    private function composeRequest(
        string $csrfToken,
        string $identity,
        string $to = 'to@example.com',
        string $cc = '',
        string $replyToMessageId = '',
    ): Request {
        return new Request('POST', '/compose', [], [
            '_csrf' => $csrfToken,
            'to' => $to,
            'cc' => $cc,
            'bcc' => '',
            'subject' => 'Subject',
            'body' => 'Body',
            'identity' => $identity,
            'reply_to_message_id' => $replyToMessageId,
        ], [], [], []);
    }

    /**
     * @return SmtpSenderInterface&object{envelope:?SendEnvelope}
     */
    private function sender(): SmtpSenderInterface
    {
        return new class implements SmtpSenderInterface {
            public ?SendEnvelope $envelope = null;

            public function send(
                MailboxCredentials $credentials,
                array $to,
                string $subject,
                string $plainBody,
                string $htmlBody,
                array $attachments = [],
            ): void {
            }

            public function sendEnvelope(MailboxCredentials $credentials, SendEnvelope $envelope): void
            {
                $this->envelope = $envelope;
            }
        };
    }

    private function send(Response $response): string
    {
        ob_start();
        $response->send();
        return (string) ob_get_clean();
    }

    private function auditLogger(?AuditEventRepositoryInterface $events = null): AuditLogger
    {
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . '/mailika-compose-audit-' . bin2hex(random_bytes(4)) . '.log';
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

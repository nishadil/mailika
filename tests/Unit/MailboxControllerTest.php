<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Auth\CredentialVault;
use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Controller\MailboxController;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Mail\Attachment;
use Mailika\Mail\MailAccountProfile;
use Mailika\Mail\MailboxCapabilities;
use Mailika\Mail\MailboxAclEntry;
use Mailika\Mail\MailboxFolder;
use Mailika\Mail\MailboxRights;
use Mailika\Mail\Message;
use Mailika\Mail\SavedSearch;
use Mailika\Mail\SessionMailAccountProfileRepository;
use Mailika\Mail\SessionMessageMetadataCacheRepository;
use Mailika\Mail\SessionSavedSearchRepository;
use Mailika\Preferences\SessionPreferencesRepository;
use Mailika\Security\CsrfTokenManager;
use Mailika\Security\HtmlSanitizer;
use Mailika\Support\View;
use Mailika\Tests\Integration\FakeMailboxClient;
use PHPUnit\Framework\TestCase;

final class MailboxControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('m', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['MAILIKA_REMOTE_IMAGES'] = 'false';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_ENV['APP_KEY'], $_ENV['MAILIKA_REMOTE_IMAGES']);
    }

    public function testMessageRewritesKnownCidImagesToInlineAttachmentUrls(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $client = new FakeMailboxClient();
        $client->message = new Message(
            '42',
            'sender@example.com',
            'smoke@example.com',
            'Inline image',
            'today',
            '<p>Hello</p><img src="cid:logo@example"><img src="cid:missing@example">',
            'Hello',
            [
                new Attachment(
                    'logo.png',
                    'image/png',
                    10,
                    'att-1',
                    true,
                    '<logo@example>',
                ),
            ],
        );

        (new CredentialVault($config))->store($this->credentials());

        $controller = new MailboxController(
            new View($root . '/templates', $root . '/public'),
            new CsrfTokenManager(),
            new CredentialVault($config),
            $client,
            new HtmlSanitizer($config),
            new SessionSavedSearchRepository(),
            new SessionMailAccountProfileRepository(),
            new SessionMessageMetadataCacheRepository(),
            new SessionPreferencesRepository(),
        );

        $html = $this->send($controller->message(new Request('GET', '/message', [
            'folder' => 'INBOX',
            'id' => '42',
        ], [], [], [], [])));

        self::assertStringContainsString('/attachment?folder=INBOX', $html);
        self::assertStringContainsString('attachment=att-1', $html);
        self::assertStringContainsString('inline=1', $html);
        self::assertStringNotContainsString('cid:logo@example', $html);
        self::assertStringNotContainsString('cid:missing@example', $html);
    }

    public function testMailboxRendersKeyboardNavigationHooks(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        (new CredentialVault($config))->store($this->credentials());

        $client = new FakeMailboxClient();
        $client->folderFixtures = [
            new MailboxFolder('INBOX', 'Inbox'),
            new MailboxFolder('Archive', 'Archive'),
        ];

        $controller = new MailboxController(
            new View($root . '/templates', $root . '/public'),
            new CsrfTokenManager(),
            new CredentialVault($config),
            $client,
            new HtmlSanitizer($config),
            new SessionSavedSearchRepository(),
            new SessionMailAccountProfileRepository(),
            new SessionMessageMetadataCacheRepository(),
            new SessionPreferencesRepository(),
        );

        $html = $this->send($controller->index(new Request('GET', '/mailbox', [], [], [], [], [])));

        self::assertStringContainsString('data-mailbox-shortcuts', $html);
        self::assertStringContainsString('data-mailbox-search', $html);
        self::assertStringContainsString('data-compose-link', $html);
        self::assertStringContainsString('data-message-row', $html);
        self::assertStringContainsString('data-message-link', $html);
        self::assertStringContainsString('data-reply-url="/compose?mode=reply&amp;folder=INBOX&amp;id=1"', $html);
        self::assertStringContainsString('data-folder-drop-target', $html);
        self::assertStringContainsString('data-drag-move-form', $html);
        self::assertStringContainsString('data-message-id="1"', $html);
        self::assertStringContainsString('draggable="true"', $html);
    }

    public function testMailboxRendersSavedSearchDeleteForm(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $searches = new SessionSavedSearchRepository();
        (new CredentialVault($config))->store($this->credentials());
        $searches->save('smoke@example.com', new SavedSearch('Invoices', 'INBOX', 'invoice'));

        $controller = new MailboxController(
            new View($root . '/templates', $root . '/public'),
            new CsrfTokenManager(),
            new CredentialVault($config),
            new FakeMailboxClient(),
            new HtmlSanitizer($config),
            $searches,
            new SessionMailAccountProfileRepository(),
            new SessionMessageMetadataCacheRepository(),
            new SessionPreferencesRepository(),
        );

        $html = $this->send($controller->index(new Request('GET', '/mailbox', [], [], [], [], [])));

        self::assertStringContainsString('action="/searches/delete"', $html);
        self::assertStringContainsString('name="id" value="1"', $html);
        self::assertStringContainsString('Invoices', $html);
    }

    public function testMailboxParsesAdvancedSearchAndPreservesCanonicalQuery(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        (new CredentialVault($config))->store($this->credentials());

        $client = new FakeMailboxClient();
        $controller = new MailboxController(
            new View($root . '/templates', $root . '/public'),
            new CsrfTokenManager(),
            new CredentialVault($config),
            $client,
            new HtmlSanitizer($config),
            new SessionSavedSearchRepository(),
            new SessionMailAccountProfileRepository(),
            new SessionMessageMetadataCacheRepository(),
            new SessionPreferencesRepository(),
        );

        $html = $this->send($controller->index(new Request('GET', '/mailbox', [
            'q' => 'from:reports@example.com is:flagged security',
            'subject' => 'Quarterly report',
            'unseen' => '1',
        ], [], [], [], [])));

        self::assertNotNull($client->lastSearchCriteria);
        self::assertSame('security', $client->lastSearchCriteria->query);
        self::assertSame('reports@example.com', $client->lastSearchCriteria->from);
        self::assertSame('Quarterly report', $client->lastSearchCriteria->subject);
        self::assertTrue($client->lastSearchCriteria->unseenOnly);
        self::assertTrue($client->lastSearchCriteria->flaggedOnly);
        self::assertStringContainsString('Advanced search', $html);
        self::assertStringContainsString('name="from" value="reports@example.com"', $html);
        self::assertStringContainsString('name="subject" value="Quarterly report"', $html);
        self::assertStringContainsString('name="unseen" value="1" checked', $html);
        self::assertStringContainsString('name="flagged" value="1" checked', $html);
        self::assertStringContainsString(
            'from:reports@example.com subject:&quot;Quarterly report&quot; is:unread is:flagged security',
            $html,
        );
    }

    public function testMailboxRendersSavedAccountProfilesForQuickSwitch(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $profiles = new SessionMailAccountProfileRepository();
        (new CredentialVault($config))->store($this->credentials());
        $profiles->save('smoke@example.com', new MailAccountProfile(
            'Work mailbox',
            'work@example.com',
            'imap.work.example.com',
            993,
            true,
            'smtp.work.example.com',
            587,
            'starttls',
        ));

        $controller = new MailboxController(
            new View($root . '/templates', $root . '/public'),
            new CsrfTokenManager(),
            new CredentialVault($config),
            new FakeMailboxClient(),
            new HtmlSanitizer($config),
            new SessionSavedSearchRepository(),
            $profiles,
            new SessionMessageMetadataCacheRepository(),
            new SessionPreferencesRepository(),
        );

        $html = $this->send($controller->index(new Request('GET', '/mailbox', [], [], [], [], [])));

        self::assertStringContainsString('Accounts', $html);
        self::assertStringContainsString('Work mailbox', $html);
        self::assertStringContainsString('work@example.com', $html);
        self::assertStringContainsString('action="/accounts/select"', $html);
        self::assertStringContainsString('name="id" value="1"', $html);
        self::assertStringNotContainsString('imap.work.example.com', $html);
        self::assertStringNotContainsString('smtp.work.example.com', $html);
        self::assertStringNotContainsString('secret', $html);
    }

    public function testMailboxRendersAclRightsWhenServerReportsThem(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        (new CredentialVault($config))->store($this->credentials());

        $client = new FakeMailboxClient();
        $client->capabilitiesFixture = new MailboxCapabilities(move: true, acl: true);
        $client->rightsFixture = new MailboxRights('lrswite');

        $controller = new MailboxController(
            new View($root . '/templates', $root . '/public'),
            new CsrfTokenManager(),
            new CredentialVault($config),
            $client,
            new HtmlSanitizer($config),
            new SessionSavedSearchRepository(),
            new SessionMailAccountProfileRepository(),
            new SessionMessageMetadataCacheRepository(),
            new SessionPreferencesRepository(),
        );

        $html = $this->send($controller->index(new Request('GET', '/mailbox', [], [], [], [], [])));

        self::assertStringContainsString('ACL', $html);
        self::assertStringContainsString('IMAP MYRIGHTS lrswite', $html);
        self::assertStringContainsString('Rights Lookup, Read, Seen, Flags, Insert, Delete mail, Expunge', $html);
    }

    public function testMailboxRendersAclEntriesWhenServerReportsThem(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        (new CredentialVault($config))->store($this->credentials());

        $client = new FakeMailboxClient();
        $client->capabilitiesFixture = new MailboxCapabilities(move: true, acl: true);
        $client->rightsFixture = new MailboxRights('lrswite');
        $client->aclFixtures = [
            new MailboxAclEntry('smoke@example.com', new MailboxRights('lrswite')),
            new MailboxAclEntry('support@example.com', new MailboxRights('lr')),
        ];

        $controller = new MailboxController(
            new View($root . '/templates', $root . '/public'),
            new CsrfTokenManager(),
            new CredentialVault($config),
            $client,
            new HtmlSanitizer($config),
            new SessionSavedSearchRepository(),
            new SessionMailAccountProfileRepository(),
            new SessionMessageMetadataCacheRepository(),
            new SessionPreferencesRepository(),
        );

        $html = $this->send($controller->index(new Request('GET', '/mailbox', [], [], [], [], [])));

        self::assertStringContainsString('Folder access', $html);
        self::assertStringContainsString('smoke@example.com', $html);
        self::assertStringContainsString('support@example.com', $html);
        self::assertStringContainsString('IMAP rights lr', $html);
        self::assertStringContainsString('Lookup, Read', $html);
    }

    public function testMailboxRendersAclEditorForAclAdmin(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        (new CredentialVault($config))->store($this->credentials());

        $client = new FakeMailboxClient();
        $client->capabilitiesFixture = new MailboxCapabilities(move: true, acl: true);
        $client->rightsFixture = new MailboxRights('lra');
        $client->aclFixtures = [
            new MailboxAclEntry('support@example.com', new MailboxRights('lr')),
        ];

        $controller = new MailboxController(
            new View($root . '/templates', $root . '/public'),
            new CsrfTokenManager(),
            new CredentialVault($config),
            $client,
            new HtmlSanitizer($config),
            new SessionSavedSearchRepository(),
            new SessionMailAccountProfileRepository(),
            new SessionMessageMetadataCacheRepository(),
            new SessionPreferencesRepository(),
        );

        $html = $this->send($controller->index(new Request('GET', '/mailbox', [], [], [], [], [])));

        self::assertStringContainsString('action="/folders/acl"', $html);
        self::assertStringContainsString('action="/folders/acl/delete"', $html);
        self::assertStringContainsString('Save access', $html);
        self::assertStringContainsString('Revoke', $html);
    }

    public function testMailboxHidesWriteControlsWhenAclRightsAreReadOnly(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        (new CredentialVault($config))->store($this->credentials());

        $client = new FakeMailboxClient();
        $client->folderFixtures = [
            new MailboxFolder('INBOX', 'Inbox'),
            new MailboxFolder('Archive', 'Archive'),
        ];
        $client->capabilitiesFixture = new MailboxCapabilities(move: true, acl: true);
        $client->rightsFixture = new MailboxRights('lr');

        $controller = new MailboxController(
            new View($root . '/templates', $root . '/public'),
            new CsrfTokenManager(),
            new CredentialVault($config),
            $client,
            new HtmlSanitizer($config),
            new SessionSavedSearchRepository(),
            new SessionMailAccountProfileRepository(),
            new SessionMessageMetadataCacheRepository(),
            new SessionPreferencesRepository(),
        );

        $html = $this->send($controller->index(new Request('GET', '/mailbox', [], [], [], [], [])));

        self::assertStringContainsString('Rights Lookup, Read', $html);
        self::assertStringContainsString('value="copy"', $html);
        self::assertStringNotContainsString('value="flag"', $html);
        self::assertStringNotContainsString('value="delete"', $html);
        self::assertStringNotContainsString('value="move"', $html);
        self::assertStringNotContainsString('data-drag-move-form', $html);
        self::assertStringNotContainsString('action="/folders/create"', $html);
        self::assertStringNotContainsString('action="/folders/acl"', $html);
    }

    public function testDeleteSavedSearchRequiresValidCsrfToken(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $csrf = new CsrfTokenManager();
        $searches = new SessionSavedSearchRepository();
        (new CredentialVault($config))->store($this->credentials());
        $searches->save('smoke@example.com', new SavedSearch('Invoices', 'INBOX', 'invoice'));
        $search = $searches->listForMailbox('smoke@example.com')[0];

        $controller = new MailboxController(
            new View($root . '/templates', $root . '/public'),
            $csrf,
            new CredentialVault($config),
            new FakeMailboxClient(),
            new HtmlSanitizer($config),
            $searches,
            new SessionMailAccountProfileRepository(),
            new SessionMessageMetadataCacheRepository(),
            new SessionPreferencesRepository(),
        );

        $controller->deleteSearch(new Request('POST', '/searches/delete', [], [
            '_csrf' => 'invalid',
            'id' => (string) $search->id,
            'folder' => 'INBOX',
            'q' => 'invoice',
        ], [], [], []));

        self::assertCount(1, $searches->listForMailbox('smoke@example.com'));

        $response = $controller->deleteSearch(new Request('POST', '/searches/delete', [], [
            '_csrf' => $csrf->token(),
            'id' => (string) $search->id,
            'folder' => 'INBOX',
            'q' => 'invoice',
        ], [], [], []));

        self::assertSame('/mailbox?folder=INBOX&q=invoice', $response->headers()['Location']);
        self::assertSame([], $searches->listForMailbox('smoke@example.com'));
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

    private function send(Response $response): string
    {
        ob_start();
        $response->send();
        return (string) ob_get_clean();
    }
}

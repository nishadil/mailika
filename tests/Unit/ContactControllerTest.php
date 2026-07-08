<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Audit\AuditEventRepositoryInterface;
use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Contact\Contact;
use Mailika\Contact\ContactDirectoryInterface;
use Mailika\Contact\ContactGroup;
use Mailika\Contact\NullContactDirectory;
use Mailika\Contact\SessionContactGroupRepository;
use Mailika\Contact\SessionContactRepository;
use Mailika\Contact\VcardParser;
use Mailika\Controller\ContactController;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Security\CsrfTokenManager;
use Mailika\Support\View;
use Mailika\Tests\Fixtures\FakeContactDirectory;
use Mailika\Tests\Fixtures\InMemoryAuditEventRepository;
use PHPUnit\Framework\TestCase;

final class ContactControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('c', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_ENV['APP_KEY'], $_ENV['LOG_PATH']);
    }

    public function testIndexRendersDeleteFormForContacts(): void
    {
        $contacts = new SessionContactRepository();
        $groups = new SessionContactGroupRepository();
        $controller = $this->controller($contacts, groups: $groups);
        $credentials = $this->login();
        $contacts->save($credentials->email, new Contact('Alice', 'alice@example.com'));
        $groups->save($credentials->email, new ContactGroup('Team'));

        $html = $this->send($controller->index(new Request('GET', '/contacts', [], [], [], [], [])));

        self::assertStringContainsString('action="/contacts/delete"', $html);
        self::assertStringContainsString('name="email" value="alice@example.com"', $html);
        self::assertStringContainsString('action="/contacts/groups/delete"', $html);
        self::assertStringContainsString('name="group_id"', $html);
        self::assertStringContainsString('Delete', $html);
    }

    public function testDeleteRequiresValidCsrfToken(): void
    {
        $contacts = new SessionContactRepository();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($contacts, $csrf);
        $credentials = $this->login();
        $contacts->save($credentials->email, new Contact('Alice', 'alice@example.com'));

        $controller->delete(new Request('POST', '/contacts/delete', [], [
            '_csrf' => 'invalid',
            'email' => 'alice@example.com',
        ], [], [], []));

        self::assertCount(1, $contacts->listForMailbox($credentials->email));

        $response = $controller->delete(new Request('POST', '/contacts/delete', [], [
            '_csrf' => $csrf->token(),
            'email' => 'alice@example.com',
        ], [], [], []));

        self::assertSame('/contacts', $response->headers()['Location']);
        self::assertSame([], $contacts->listForMailbox($credentials->email));
    }

    public function testDeleteGroupRequiresValidCsrfToken(): void
    {
        $contacts = new SessionContactRepository();
        $groups = new SessionContactGroupRepository();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($contacts, $csrf, $groups);
        $credentials = $this->login();
        $groups->save($credentials->email, new ContactGroup('Team'));
        $group = $groups->listForMailbox($credentials->email)[0];

        $controller->deleteGroup(new Request('POST', '/contacts/groups/delete', [], [
            '_csrf' => 'invalid',
            'group_id' => (string) $group->id,
        ], [], [], []));

        self::assertCount(1, $groups->listForMailbox($credentials->email));

        $response = $controller->deleteGroup(new Request('POST', '/contacts/groups/delete', [], [
            '_csrf' => $csrf->token(),
            'group_id' => (string) $group->id,
        ], [], [], []));

        self::assertSame('/contacts', $response->headers()['Location']);
        self::assertSame([], $groups->listForMailbox($credentials->email));
    }

    public function testImportCreatesGroupsFromVcardCategories(): void
    {
        $contacts = new SessionContactRepository();
        $groups = new SessionContactGroupRepository();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($contacts, $csrf, $groups);
        $credentials = $this->login();

        $response = $controller->import(new Request('POST', '/contacts/import', [], [
            '_csrf' => $csrf->token(),
            'vcard_text' => "BEGIN:VCARD\r\n"
                . "FN:Alice Example\r\n"
                . "EMAIL:alice@example.com\r\n"
                . "CATEGORIES:Team,VIP\r\n"
                . "END:VCARD\r\n",
        ], [], [], []));

        $stored = $contacts->listForMailbox($credentials->email);
        self::assertSame('/contacts', $response->headers()['Location']);
        self::assertCount(1, $stored);
        self::assertSame(['Team', 'VIP'], $stored[0]->groups);
        self::assertSame(['Team', 'VIP'], array_map(
            static fn (ContactGroup $group): string => $group->name,
            $groups->listForMailbox($credentials->email),
        ));
    }

    public function testContactMutationsWriteAggregateAuditWithoutContactData(): void
    {
        $contacts = new SessionContactRepository();
        $groups = new SessionContactGroupRepository();
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $controller = $this->controller($contacts, $csrf, $groups, $events);
        $this->login();
        $token = $csrf->token();

        $controller->save(new Request('POST', '/contacts', [], [
            '_csrf' => $token,
            'display_name' => 'Alice Example',
            'email' => 'alice@example.com',
            'notes' => 'Private note',
            'group_id' => '0',
        ], [], [], []));
        $controller->import(new Request('POST', '/contacts/import', [], [
            '_csrf' => $token,
            'vcard_text' => "BEGIN:VCARD\r\nFN:Bob\r\nEMAIL:bob@example.com\r\nEND:VCARD\r\n",
        ], [], [], []));
        $controller->delete(new Request('POST', '/contacts/delete', [], [
            '_csrf' => $token,
            'email' => 'alice@example.com',
        ], [], [], []));

        self::assertSame(
            ['contact.saved', 'contacts.imported', 'contact.deleted'],
            array_column($events->records, 'event_type'),
        );
        self::assertSame('1', $events->records[1]['metadata']['count']);
        foreach ($events->records as $record) {
            self::assertArrayNotHasKey('email', $record['metadata']);
            self::assertArrayNotHasKey('display_name', $record['metadata']);
            self::assertArrayNotHasKey('notes', $record['metadata']);
        }
    }

    public function testDirectorySearchRendersResultsAndWritesAggregateAudit(): void
    {
        $contacts = new SessionContactRepository();
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $directory = new FakeContactDirectory([
            new Contact('Alice Directory', 'alice.directory@example.com', 'Imported from LDAP directory'),
            new Contact('Bob Directory', 'bob.directory@example.com', 'Imported from LDAP directory'),
        ]);
        $controller = $this->controller(
            $contacts,
            $csrf,
            auditEvents: $events,
            directory: $directory,
        );
        $this->login();

        $response = $controller->index(new Request('GET', '/contacts', [
            'directory_q' => 'alice',
        ], [], [], [], []));
        $html = $this->send($response);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Fixture directory', $html);
        self::assertStringContainsString('Alice Directory', $html);
        self::assertStringContainsString('alice.directory@example.com', $html);
        self::assertStringContainsString('action="/contacts"', $html);
        self::assertStringNotContainsString('Bob Directory', $html);
        self::assertSame('contact_directory.searched', $events->records[0]['event_type']);
        self::assertSame('5', $events->records[0]['metadata']['query_length']);
        self::assertSame('1', $events->records[0]['metadata']['count']);
        self::assertArrayNotHasKey('query', $events->records[0]['metadata']);
    }

    public function testExportEscapesVcardTextAndRoundTripsNotesAndCategories(): void
    {
        $contacts = new SessionContactRepository();
        $groups = new SessionContactGroupRepository();
        $controller = $this->controller($contacts, groups: $groups);
        $credentials = $this->login();
        $contacts->save(
            $credentials->email,
            new Contact('Alice, CEO; Example', 'alice@example.com', "Line one\nLine two\\done"),
        );
        $groups->save($credentials->email, new ContactGroup('VIP, Tier'));
        $group = $groups->listForMailbox($credentials->email)[0];
        $groups->assignContactByEmail($credentials->email, 'alice@example.com', (int) $group->id);

        $response = $controller->export(new Request('GET', '/contacts/export', [], [], [], [], []));
        $body = $this->send($response);

        self::assertSame('text/vcard; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertStringContainsString('FN:Alice\\, CEO\\; Example', $body);
        self::assertStringContainsString('NOTE:Line one\\nLine two\\\\done', $body);
        self::assertStringContainsString('CATEGORIES:VIP\\, Tier', $body);
        self::assertStringNotContainsString("Line one\nLine two", $body);

        $roundTrip = (new VcardParser())->parse($body);
        self::assertCount(1, $roundTrip);
        self::assertSame('Alice, CEO; Example', $roundTrip[0]->displayName);
        self::assertSame("Line one\nLine two\\done", $roundTrip[0]->notes);
        self::assertSame(['VIP, Tier'], $roundTrip[0]->groups);
    }

    private function controller(
        SessionContactRepository $contacts,
        ?CsrfTokenManager $csrf = null,
        ?SessionContactGroupRepository $groups = null,
        ?AuditEventRepositoryInterface $auditEvents = null,
        ?ContactDirectoryInterface $directory = null,
    ): ContactController {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);

        return new ContactController(
            new View($root . '/templates', $root . '/public'),
            $csrf ?? new CsrfTokenManager(),
            new CredentialVault($config),
            $contacts,
            $groups ?? new SessionContactGroupRepository(),
            new VcardParser(),
            $directory ?? new NullContactDirectory(),
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
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . '/mailika-contact-audit-' . bin2hex(random_bytes(4)) . '.log';

        return new AuditLogger(
            Config::fromEnvironment(dirname(__DIR__, 2)),
            $events ?? new InMemoryAuditEventRepository(),
        );
    }
}

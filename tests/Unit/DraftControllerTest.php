<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Audit\AuditEventRepositoryInterface;
use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Controller\DraftController;
use Mailika\Draft\DraftMessage;
use Mailika\Draft\SessionDraftRepository;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Security\CsrfTokenManager;
use Mailika\Support\View;
use Mailika\Tests\Fixtures\InMemoryAuditEventRepository;
use PHPUnit\Framework\TestCase;

final class DraftControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('d', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_ENV['APP_KEY'], $_ENV['LOG_PATH']);
    }

    public function testIndexRendersDeleteFormForDrafts(): void
    {
        $drafts = new SessionDraftRepository();
        $controller = $this->controller($drafts);
        $credentials = $this->login();
        $drafts->save($credentials->email, new DraftMessage('', 'to@example.com', '', '', 'Subject', 'Body', ''));

        $html = $this->send($controller->index(new Request('GET', '/drafts', [], [], [], [], [])));

        self::assertStringContainsString('action="/drafts/delete"', $html);
        self::assertStringContainsString('name="id"', $html);
        self::assertStringContainsString('Delete', $html);
    }

    public function testDeleteRequiresValidCsrfToken(): void
    {
        $drafts = new SessionDraftRepository();
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($drafts, $csrf);
        $credentials = $this->login();
        $drafts->save($credentials->email, new DraftMessage('', 'to@example.com', '', '', 'Subject', 'Body', ''));
        $draft = $drafts->listForMailbox($credentials->email)[0];

        $controller->delete(new Request('POST', '/drafts/delete', [], [
            '_csrf' => 'invalid',
            'id' => $draft->id,
        ], [], [], []));

        self::assertCount(1, $drafts->listForMailbox($credentials->email));

        $response = $controller->delete(new Request('POST', '/drafts/delete', [], [
            '_csrf' => $csrf->token(),
            'id' => $draft->id,
        ], [], [], []));

        self::assertSame('/drafts', $response->headers()['Location']);
        self::assertSame([], $drafts->listForMailbox($credentials->email));
    }

    public function testDeleteWritesAggregateAuditEventWithoutDraftData(): void
    {
        $drafts = new SessionDraftRepository();
        $csrf = new CsrfTokenManager();
        $events = new InMemoryAuditEventRepository();
        $controller = $this->controller($drafts, $csrf, $events);
        $credentials = $this->login();
        $drafts->save($credentials->email, new DraftMessage('', 'to@example.com', '', '', 'Subject', 'Body', ''));
        $draft = $drafts->listForMailbox($credentials->email)[0];

        $controller->delete(new Request('POST', '/drafts/delete', [], [
            '_csrf' => $csrf->token(),
            'id' => $draft->id,
        ], [], [], []));

        self::assertCount(1, $events->records);
        self::assertSame('mail.draft_deleted', $events->records[0]['event_type']);
        self::assertSame('smoke@example.com', $events->records[0]['mailbox']);
        self::assertArrayNotHasKey('id', $events->records[0]['metadata']);
        self::assertArrayNotHasKey('subject', $events->records[0]['metadata']);
    }

    private function controller(
        SessionDraftRepository $drafts,
        ?CsrfTokenManager $csrf = null,
        ?AuditEventRepositoryInterface $auditEvents = null,
    ): DraftController {
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);

        return new DraftController(
            new View($root . '/templates', $root . '/public'),
            $csrf ?? new CsrfTokenManager(),
            new CredentialVault($config),
            $drafts,
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
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . '/mailika-draft-audit-' . bin2hex(random_bytes(4)) . '.log';

        return new AuditLogger(
            Config::fromEnvironment(dirname(__DIR__, 2)),
            $events ?? new InMemoryAuditEventRepository(),
        );
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Tests\Integration;

use Mailika\Auth\MailboxCredentials;
use Mailika\Mail\FixtureMailboxClient;
use Mailika\Mail\MessageSearchCriteria;
use PHPUnit\Framework\TestCase;

final class FixtureMailboxClientTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testProvidesDeterministicFoldersMessagesAndAttachments(): void
    {
        $client = new FixtureMailboxClient();
        $credentials = $this->credentials();

        $folders = $client->folders($credentials);
        $messages = $client->search($credentials, new MessageSearchCriteria('INBOX', 'security'));
        $message = $client->message($credentials, 'INBOX', '1001');
        $attachment = $client->attachment($credentials, 'INBOX', '1001', 'logo');

        self::assertGreaterThanOrEqual(5, count($folders));
        self::assertSame('INBOX', $folders[0]->name);
        self::assertSame('Quarterly security report', $messages[0]->subject);
        self::assertSame('Welcome to Mailika', $message->subject);
        self::assertSame('mailika-logo.png', $attachment->filename);
        self::assertSame('image/png', $attachment->contentType);
    }

    public function testMutatesMessageFlagsAndFoldersInSessionState(): void
    {
        $client = new FixtureMailboxClient();
        $credentials = $this->credentials();

        $client->markSeen($credentials, 'INBOX', '1001', true);
        $client->flag($credentials, 'INBOX', '1001', true);
        $client->move($credentials, 'INBOX', '1001', 'Archive');

        $archive = $client->search($credentials, new MessageSearchCriteria('Archive', 'Welcome'));
        $inbox = $client->search($credentials, new MessageSearchCriteria('INBOX', 'Welcome'));

        self::assertCount(1, $archive);
        self::assertSame('Welcome to Mailika', $archive[0]->subject);
        self::assertTrue($archive[0]->seen);
        self::assertTrue($archive[0]->flagged);
        self::assertCount(1, $inbox);
        self::assertSame('Re: Welcome to Mailika', $inbox[0]->subject);
    }

    public function testAdvancedSearchFiltersFixtureMessages(): void
    {
        $client = new FixtureMailboxClient();
        $credentials = $this->credentials();

        $flaggedReport = $client->search(
            $credentials,
            MessageSearchCriteria::fromRawInput(
                'INBOX',
                'security',
                1,
                50,
                'reports@example.com',
                'team@example.com',
                'Quarterly',
                flaggedOnly: true,
            ),
        );
        $unreadWelcome = $client->search(
            $credentials,
            MessageSearchCriteria::fromRawInput('INBOX', 'from:nishadil.dev is:unread welcome', 1, 50),
        );

        self::assertCount(1, $flaggedReport);
        self::assertSame('1002', $flaggedReport[0]->id);
        self::assertCount(1, $unreadWelcome);
        self::assertSame('1001', $unreadWelcome[0]->id);
    }

    public function testCreatesRenamesAndDeletesFolders(): void
    {
        $client = new FixtureMailboxClient();
        $credentials = $this->credentials();

        $client->createFolder($credentials, 'Projects');
        $client->copy($credentials, 'INBOX', '1002', 'Projects');
        $client->renameFolder($credentials, 'Projects', 'Clients');
        $client->deleteFolder($credentials, 'Clients');

        $folderNames = array_map(static fn ($folder): string => $folder->name, $client->folders($credentials));

        self::assertNotContains('Projects', $folderNames);
        self::assertNotContains('Clients', $folderNames);
        self::assertSame([], $client->search($credentials, new MessageSearchCriteria('Clients')));
    }

    private function credentials(): MailboxCredentials
    {
        return new MailboxCredentials(
            'smoke@example.com',
            'secret',
            'fixture.local',
            993,
            true,
            'smtp.fixture.local',
            587,
            'starttls',
        );
    }
}

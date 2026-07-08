<?php

declare(strict_types=1);

namespace Mailika\Tests\Integration;

use Mailika\Auth\MailboxCredentials;
use Mailika\Mail\MessageSearchCriteria;
use PHPUnit\Framework\TestCase;

final class MailboxContractTest extends TestCase
{
    public function testMailboxInterfaceSupportsCoreV1Operations(): void
    {
        $client = new FakeMailboxClient();
        $credentials = new MailboxCredentials(
            'user@example.com',
            'secret',
            'imap.example.com',
            993,
            true,
            'smtp.example.com',
            587,
            'starttls',
        );

        self::assertCount(1, $client->folders($credentials));
        self::assertCount(1, $client->search($credentials, new MessageSearchCriteria('INBOX', 'hello')));

        $client->markSeen($credentials, 'INBOX', '1', true);
        $client->flag($credentials, 'INBOX', '1', true);
        $client->copy($credentials, 'INBOX', '1', 'Archive');
        $client->move($credentials, 'INBOX', '1', 'Archive');
        $client->delete($credentials, 'Archive', '1');

        self::assertSame(['seen', 'flagged', 'copy', 'move', 'delete'], $client->actions);
    }
}

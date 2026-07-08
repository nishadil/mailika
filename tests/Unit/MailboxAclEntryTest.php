<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Mail\MailboxAclEntry;
use Mailika\Mail\MailboxRights;
use PHPUnit\Framework\TestCase;

final class MailboxAclEntryTest extends TestCase
{
    public function testIdentifierIsTrimmedAndControlCharactersAreRemoved(): void
    {
        $entry = new MailboxAclEntry(" team\r\n@example.com ", new MailboxRights('lr'));

        self::assertSame('team@example.com', $entry->identifier);
        self::assertSame('lr', $entry->rights->raw);
    }

    public function testIdentifierIsBounded(): void
    {
        $entry = new MailboxAclEntry(str_repeat('a', 300), new MailboxRights('lrs'));

        self::assertSame(255, strlen($entry->identifier));
    }
}

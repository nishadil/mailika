<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Mail\MailboxRights;
use PHPUnit\Framework\TestCase;

final class MailboxRightsTest extends TestCase
{
    public function testEmptyRightsAreUnavailable(): void
    {
        $rights = new MailboxRights();

        self::assertFalse($rights->available());
        self::assertFalse($rights->canRead());
    }

    public function testStandardMailboxRightsAreMappedToCapabilities(): void
    {
        $rights = new MailboxRights('lrswipkxtea');

        self::assertTrue($rights->available());
        self::assertTrue($rights->canLookup());
        self::assertTrue($rights->canRead());
        self::assertTrue($rights->canKeepSeen());
        self::assertTrue($rights->canWriteFlags());
        self::assertTrue($rights->canInsert());
        self::assertTrue($rights->canPost());
        self::assertTrue($rights->canCreateMailbox());
        self::assertTrue($rights->canDeleteMailbox());
        self::assertTrue($rights->canDeleteMessages());
        self::assertTrue($rights->canExpunge());
        self::assertTrue($rights->canAdminister());
    }

    public function testRightsDiscardNonAlphabeticServerTokens(): void
    {
        $rights = new MailboxRights('lr sw;!');

        self::assertSame('lrsw', $rights->raw);
        self::assertTrue($rights->canRead());
        self::assertTrue($rights->canWriteFlags());
    }
}

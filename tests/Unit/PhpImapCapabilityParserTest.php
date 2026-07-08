<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Mail\PhpImapCapabilityParser;
use PHPUnit\Framework\TestCase;

final class PhpImapCapabilityParserTest extends TestCase
{
    public function testParsesKnownCapabilityFlags(): void
    {
        $capabilities = (new PhpImapCapabilityParser())->parse([
            'imap4rev1',
            'move',
            'quota',
            'acl',
            'idle',
            'sort',
            'thread=references',
        ]);

        self::assertTrue($capabilities->move);
        self::assertTrue($capabilities->quota);
        self::assertTrue($capabilities->acl);
        self::assertTrue($capabilities->idle);
        self::assertTrue($capabilities->sort);
        self::assertTrue($capabilities->thread);
        self::assertSame(
            ['LEGACY-EXT-IMAP', 'IMAP4REV1', 'MOVE', 'QUOTA', 'ACL', 'IDLE', 'SORT', 'THREAD=REFERENCES'],
            $capabilities->raw,
        );
    }

    public function testParsesOrderedSubjectThreadCapability(): void
    {
        $capabilities = (new PhpImapCapabilityParser())->parse(['THREAD=ORDEREDSUBJECT']);

        self::assertTrue($capabilities->thread);
    }

    public function testDeduplicatesAndIgnoresBlankCapabilities(): void
    {
        $capabilities = (new PhpImapCapabilityParser())->parse([' move ', 'MOVE', '']);

        self::assertSame(['LEGACY-EXT-IMAP', 'MOVE'], $capabilities->raw);
    }
}

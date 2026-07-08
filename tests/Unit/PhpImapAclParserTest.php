<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Mail\PhpImapAclParser;
use PHPUnit\Framework\TestCase;

final class PhpImapAclParserTest extends TestCase
{
    public function testParsesNativeAssociativeAclMap(): void
    {
        $entries = (new PhpImapAclParser())->parse([
            'owner@example.com' => 'lrswipkxtea',
            'support@example.com' => 'lr',
        ]);

        self::assertCount(2, $entries);
        self::assertSame('owner@example.com', $entries[0]->identifier);
        self::assertSame('lrswipkxtea', $entries[0]->rights->raw);
        self::assertSame('support@example.com', $entries[1]->identifier);
        self::assertSame('lr', $entries[1]->rights->raw);
    }

    public function testIgnoresInvalidAclEntries(): void
    {
        $entries = (new PhpImapAclParser())->parse([
            '' => 'lr',
            'empty-rights@example.com' => '',
            'valid@example.com' => 'lr',
        ]);

        self::assertCount(1, $entries);
        self::assertSame('valid@example.com', $entries[0]->identifier);
    }

    public function testReturnsRightsForExactIdentifier(): void
    {
        $parser = new PhpImapAclParser();
        $entries = $parser->parse([
            'owner@example.com' => 'lrswipkxtea',
            'support@example.com' => 'lr',
        ]);

        self::assertSame('lrswipkxtea', $parser->rightsFor($entries, 'OWNER@example.com')->raw);
        self::assertSame('', $parser->rightsFor($entries, 'missing@example.com')->raw);
    }

    public function testReturnsEmptyListForUnknownShape(): void
    {
        self::assertSame([], (new PhpImapAclParser())->parse(false));
    }
}

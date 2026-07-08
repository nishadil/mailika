<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Mail\MessageSearchCriteria;
use PHPUnit\Framework\TestCase;

final class MessageSearchCriteriaTest extends TestCase
{
    public function testParsesSearchOperatorsAndBuildsCanonicalQuery(): void
    {
        $criteria = MessageSearchCriteria::fromRawInput(
            'INBOX',
            'from:reports@example.com subject:"Quarterly security" is:unread is:starred report',
            0,
            500,
        );

        self::assertSame('INBOX', $criteria->folder);
        self::assertSame('report', $criteria->query);
        self::assertSame(1, $criteria->page);
        self::assertSame(100, $criteria->limit);
        self::assertSame('reports@example.com', $criteria->from);
        self::assertSame('Quarterly security', $criteria->subject);
        self::assertTrue($criteria->unseenOnly);
        self::assertTrue($criteria->flaggedOnly);
        self::assertSame(
            'from:reports@example.com subject:"Quarterly security" is:unread is:flagged report',
            $criteria->canonicalQuery(),
        );
    }

    public function testExplicitSearchFieldsOverrideOperatorValues(): void
    {
        $criteria = MessageSearchCriteria::fromRawInput(
            'INBOX',
            'from:wrong@example.com subject:Wrong is:flagged invoice',
            2,
            25,
            'billing@example.com',
            'finance@example.com',
            'Invoice',
        );

        self::assertSame('invoice', $criteria->query);
        self::assertSame(2, $criteria->page);
        self::assertSame(25, $criteria->limit);
        self::assertSame('billing@example.com', $criteria->from);
        self::assertSame('finance@example.com', $criteria->to);
        self::assertSame('Invoice', $criteria->subject);
        self::assertTrue($criteria->flaggedOnly);
    }
}

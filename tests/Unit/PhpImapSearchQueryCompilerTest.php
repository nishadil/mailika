<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Mail\MessageSearchCriteria;
use Mailika\Mail\PhpImapSearchQueryCompiler;
use PHPUnit\Framework\TestCase;

final class PhpImapSearchQueryCompilerTest extends TestCase
{
    public function testCompilesEmptyCriteriaToAll(): void
    {
        $compiler = new PhpImapSearchQueryCompiler();

        self::assertSame('ALL', $compiler->compile(new MessageSearchCriteria()));
    }

    public function testCompilesTextFlagsAndHeaderCriteria(): void
    {
        $compiler = new PhpImapSearchQueryCompiler();
        $criteria = new MessageSearchCriteria(
            'INBOX',
            'security report',
            1,
            50,
            unseenOnly: true,
            flaggedOnly: true,
            from: 'reports@example.com',
            to: 'team@example.com',
            subject: 'Quarterly report',
        );

        self::assertSame(
            'TEXT "security report" UNSEEN FLAGGED FROM "reports@example.com" '
                . 'TO "team@example.com" SUBJECT "Quarterly report"',
            $compiler->compile($criteria),
        );
    }

    public function testEscapesQuotesBackslashesAndControlCharacters(): void
    {
        $compiler = new PhpImapSearchQueryCompiler();
        $criteria = new MessageSearchCriteria(
            query: "urgent \"mail\"\nfolder\\one",
        );

        self::assertSame('TEXT "urgent \"mail\" folder\\\\one"', $compiler->compile($criteria));
    }
}

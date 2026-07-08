<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Contact\VcardParser;
use PHPUnit\Framework\TestCase;

final class VcardParserTest extends TestCase
{
    public function testParsesValidContactsAndSkipsInvalidCards(): void
    {
        $contacts = (new VcardParser())->parse(
            "BEGIN:VCARD\r\n"
            . "VERSION:4.0\r\n"
            . "FN:Alice Example\r\n"
            . "EMAIL;TYPE=work:alice@example.com\r\n"
            . "NOTE:Line one\\nLine two\r\n"
            . "END:VCARD\r\n"
            . "BEGIN:VCARD\r\n"
            . "FN:Invalid\r\n"
            . "EMAIL:not-an-email\r\n"
            . "END:VCARD\r\n",
        );

        self::assertCount(1, $contacts);
        self::assertSame('Alice Example', $contacts[0]->displayName);
        self::assertSame('alice@example.com', $contacts[0]->email);
        self::assertSame("Line one\nLine two", $contacts[0]->notes);
        self::assertSame([], $contacts[0]->groups);
    }

    public function testUnfoldsLongVcardLines(): void
    {
        $contacts = (new VcardParser())->parse(
            "BEGIN:VCARD\nFN:Long\nEMAIL:long@\n example.com\nEND:VCARD",
        );

        self::assertCount(1, $contacts);
        self::assertSame('long@example.com', $contacts[0]->email);
    }

    public function testNormalizesInternationalizedEmailDomains(): void
    {
        $contacts = (new VcardParser())->parse(
            "BEGIN:VCARD\r\nFN:Idn\r\nEMAIL:user@bücher.example\r\nEND:VCARD",
        );

        self::assertCount(1, $contacts);
        self::assertSame('user@xn--bcher-kva.example', $contacts[0]->email);
    }

    public function testParsesVcardCategoriesAsContactGroups(): void
    {
        $contacts = (new VcardParser())->parse(
            "BEGIN:VCARD\r\n"
            . "FN:Alice Example\r\n"
            . "EMAIL:alice@example.com\r\n"
            . "CATEGORIES:Team,VIP\\, Tier,Team\r\n"
            . "END:VCARD\r\n",
        );

        self::assertCount(1, $contacts);
        self::assertSame(['Team', 'VIP, Tier'], $contacts[0]->groups);
    }
}

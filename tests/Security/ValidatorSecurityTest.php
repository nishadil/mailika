<?php

declare(strict_types=1);

namespace Mailika\Tests\Security;

use Mailika\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorSecurityTest extends TestCase
{
    public function testRejectsHeaderInjectionCharacters(): void
    {
        self::assertTrue(Validator::headerText('Quarterly report'));
        self::assertFalse(Validator::headerText("Quarterly\r\nBcc: attacker@example.com"));
        self::assertFalse(Validator::headerText("Quarterly\0report"));
    }

    public function testParsesRecipientLists(): void
    {
        self::assertSame(
            ['alice@example.com', 'bob@example.com'],
            Validator::emailList('alice@example.com, bob@example.com; alice@example.com'),
        );
    }

    public function testNormalizesInternationalizedDomains(): void
    {
        self::assertSame('user@xn--bcher-kva.example', Validator::normalizeEmail('user@bücher.example'));
        self::assertSame(['user@xn--bcher-kva.example'], Validator::emailList('user@bücher.example'));
        self::assertTrue(Validator::email('user@bücher.example'));
        self::assertFalse(Validator::emailRequiresSmtpUtf8('user@bücher.example'));
    }

    public function testAcceptsUnicodeLocalPartsWhenSmtpUtf8IsRequired(): void
    {
        self::assertTrue(Validator::email('büro@example.com'));
        self::assertTrue(Validator::emailRequiresSmtpUtf8('büro@example.com'));
        self::assertFalse(Validator::email("büro\r\nBcc: attacker@example.com"));
    }

    public function testValidatesTcpPorts(): void
    {
        self::assertTrue(Validator::tcpPort(1));
        self::assertTrue(Validator::tcpPort(65535));
        self::assertFalse(Validator::tcpPort(0));
        self::assertFalse(Validator::tcpPort(65536));
    }
}

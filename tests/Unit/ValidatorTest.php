<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testHostAllowListSupportsExactAndSubdomainMatches(): void
    {
        self::assertTrue(Validator::hostAllowed('imap.example.com', ['imap.example.com']));
        self::assertTrue(Validator::hostAllowed('mail.users.example.com', ['example.com']));
        self::assertFalse(Validator::hostAllowed('example.net', ['example.com']));
        self::assertFalse(Validator::hostAllowed('bad host', ['*']));
    }
}

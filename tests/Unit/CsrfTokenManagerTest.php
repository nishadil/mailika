<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Security\CsrfTokenManager;
use PHPUnit\Framework\TestCase;

final class CsrfTokenManagerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testTokenValidationAndRotation(): void
    {
        $manager = new CsrfTokenManager();
        $token = $manager->token();

        self::assertTrue($manager->validate($token));
        self::assertFalse($manager->validate('wrong-token'));

        $manager->rotate();

        self::assertFalse($manager->validate($token));
        self::assertTrue($manager->validate($manager->token()));
    }
}

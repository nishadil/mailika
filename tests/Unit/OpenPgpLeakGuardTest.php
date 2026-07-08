<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Crypto\OpenPgpLeakException;
use Mailika\Crypto\OpenPgpLeakGuard;
use PHPUnit\Framework\TestCase;

final class OpenPgpLeakGuardTest extends TestCase
{
    public function testAllowsPublicKeyArmor(): void
    {
        $guard = new OpenPgpLeakGuard();

        $guard->assertNoPrivateKeyMaterial("-----BEGIN PGP PUBLIC KEY BLOCK-----\npublic\n");

        self::assertFalse($guard->containsPrivateKeyArmor('plain body'));
    }

    public function testRejectsPrivateKeyArmorInBody(): void
    {
        $this->expectException(OpenPgpLeakException::class);

        (new OpenPgpLeakGuard())->assertNoPrivateKeyMaterial(
            "-----BEGIN PGP PRIVATE KEY BLOCK-----\nsecret\n-----END PGP PRIVATE KEY BLOCK-----",
        );
    }

    public function testRejectsPrivateKeyArmorInAttachment(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mailika-pgp-');
        self::assertIsString($path);
        file_put_contents($path, "-----BEGIN PGP PRIVATE KEY BLOCK-----\nsecret\n");

        try {
            $this->expectException(OpenPgpLeakException::class);
            (new OpenPgpLeakGuard())->assertNoPrivateKeyMaterial('', [[
                'path' => $path,
                'name' => 'secret.asc',
                'mime' => 'application/pgp-keys',
            ]]);
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsPrivateKeyArmorBeyondFirstMegabyte(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mailika-pgp-');
        self::assertIsString($path);
        file_put_contents(
            $path,
            str_repeat('x', 1_100_000) . "-----BEGIN PGP PRIVATE KEY BLOCK-----\nsecret\n",
        );

        try {
            $this->expectException(OpenPgpLeakException::class);
            (new OpenPgpLeakGuard())->assertNoPrivateKeyMaterial('', [[
                'path' => $path,
                'name' => 'late-secret.asc',
                'mime' => 'application/pgp-keys',
            ]]);
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsPrivateKeyArmorSplitAcrossReadBoundary(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mailika-pgp-');
        self::assertIsString($path);
        $armor = '-----BEGIN PGP PRIVATE KEY BLOCK-----';
        $prefix = str_repeat('x', 8192 - 10);
        file_put_contents($path, $prefix . $armor . "\nsecret\n");

        try {
            $this->expectException(OpenPgpLeakException::class);
            (new OpenPgpLeakGuard())->assertNoPrivateKeyMaterial('', [[
                'path' => $path,
                'name' => 'split-secret.asc',
                'mime' => 'application/pgp-keys',
            ]]);
        } finally {
            @unlink($path);
        }
    }
}

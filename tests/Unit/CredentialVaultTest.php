<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Auth\CredentialVault;
use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use PHPUnit\Framework\TestCase;

final class CredentialVaultTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('a', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function testStoresCredentialsEncryptedInSession(): void
    {
        $vault = new CredentialVault(Config::fromEnvironment(dirname(__DIR__, 2)));
        $credentials = new MailboxCredentials(
            'user@example.com',
            'secret-password',
            'imap.example.com',
            993,
            true,
            'smtp.example.com',
            587,
            'starttls',
        );

        $vault->store($credentials);

        self::assertIsArray($_SESSION['mailika_mailbox']);
        self::assertNotSame('secret-password', $_SESSION['mailika_mailbox']['password']);
        self::assertStringNotContainsString('secret-password', serialize($_SESSION['mailika_mailbox']));

        $opened = $vault->current();
        self::assertNotNull($opened);
        self::assertSame('secret-password', $opened->password);
        self::assertSame('user@example.com', $opened->email);
    }
}

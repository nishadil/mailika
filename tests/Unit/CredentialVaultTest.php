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
        unset($_ENV['SESSION_LIFETIME_SECONDS']);
    }

    public function testStoresCredentialsEncryptedInSession(): void
    {
        $vault = new CredentialVault(Config::fromEnvironment(dirname(__DIR__, 2)));
        $credentials = $this->credentials();

        $vault->store($credentials);

        self::assertIsArray($_SESSION['mailika_mailbox']);
        self::assertSame(2, $_SESSION['mailika_mailbox']['version']);
        self::assertIsString($_SESSION['mailika_mailbox']['payload']);
        self::assertStringNotContainsString('secret-password', serialize($_SESSION['mailika_mailbox']));
        self::assertStringNotContainsString('user@example.com', serialize($_SESSION['mailika_mailbox']));
        self::assertStringNotContainsString('imap.example.com', serialize($_SESSION['mailika_mailbox']));
        self::assertStringNotContainsString('smtp.example.com', serialize($_SESSION['mailika_mailbox']));
        self::assertIsInt($_SESSION['mailika_mailbox']['issued_at']);
        self::assertIsInt($_SESSION['mailika_mailbox']['expires_at']);
        self::assertGreaterThan($_SESSION['mailika_mailbox']['issued_at'], $_SESSION['mailika_mailbox']['expires_at']);

        $opened = $vault->current();
        self::assertNotNull($opened);
        self::assertSame('secret-password', $opened->password);
        self::assertSame('user@example.com', $opened->email);
    }

    public function testExpiredCredentialPayloadIsCleared(): void
    {
        $_ENV['SESSION_LIFETIME_SECONDS'] = '1';
        $vault = new CredentialVault(Config::fromEnvironment(dirname(__DIR__, 2)));
        $vault->store($this->credentials());

        $_SESSION['mailika_mailbox']['expires_at'] = time() - 1;

        self::assertNull($vault->current());
        self::assertArrayNotHasKey('mailika_mailbox', $_SESSION);
    }

    public function testTamperedCredentialPayloadIsCleared(): void
    {
        $vault = new CredentialVault(Config::fromEnvironment(dirname(__DIR__, 2)));
        $vault->store($this->credentials());

        $_SESSION['mailika_mailbox']['payload'] = 'not-valid';

        self::assertNull($vault->current());
        self::assertArrayNotHasKey('mailika_mailbox', $_SESSION);
    }

    private function credentials(): MailboxCredentials
    {
        return new MailboxCredentials(
            'user@example.com',
            'secret-password',
            'imap.example.com',
            993,
            true,
            'smtp.example.com',
            587,
            'starttls',
        );
    }
}

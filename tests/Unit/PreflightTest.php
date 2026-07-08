<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use FilesystemIterator;
use Mailika\Config\Config;
use Mailika\Install\Preflight;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PreflightTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mailika-preflight-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public', 0775, true);
        $this->configureValidEnvironment();
    }

    protected function tearDown(): void
    {
        $keys = [
            'APP_ENV',
            'APP_DEBUG',
            'APP_KEY',
            'APP_URL',
            'DB_DSN',
            'DB_USER',
            'DB_PASSWORD',
            'SESSION_SECURE_COOKIE',
            'MAILIKA_IMAP_ADAPTER',
            'MAILIKA_REQUIRE_TLS',
            'MAILIKA_ALLOWED_IMAP_HOSTS',
            'MAILIKA_ALLOWED_SMTP_HOSTS',
            'MAILIKA_DEFAULT_IMAP_PORT',
            'MAILIKA_DEFAULT_SMTP_PORT',
            'MAILIKA_SIEVE_ENABLED',
            'MAILIKA_SIEVE_PORT',
            'MAILIKA_SIEVE_REQUIRE_TLS',
            'MAILIKA_ALLOWED_SIEVE_HOSTS',
            'MAILIKA_LDAP_ENABLED',
            'MAILIKA_LDAP_PORT',
            'MAILIKA_LDAP_REQUIRE_TLS',
            'MAILIKA_ALLOWED_LDAP_HOSTS',
        ];

        foreach ($keys as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        $this->removeDirectory($this->root);
    }

    public function testInstallPreflightPassesAndCreatesStorageDirectories(): void
    {
        touch($this->root . '/.env');

        $issues = $this->preflight()->installIssues();

        self::assertSame([], $issues);
        self::assertDirectoryExists($this->root . '/storage/cache');
        self::assertDirectoryExists($this->root . '/storage/logs');
        self::assertDirectoryExists($this->root . '/storage/sessions');
        self::assertDirectoryExists($this->root . '/storage/uploads');
    }

    public function testInstallPreflightRequiresEnvironmentFile(): void
    {
        $issues = $this->preflight()->installIssues();

        self::assertStringContainsString('.env is missing', implode("\n", $issues));
    }

    public function testInstallPreflightRequiresValidAppKey(): void
    {
        touch($this->root . '/.env');
        $_ENV['APP_KEY'] = 'base64:replace-with-output-from-php-bin-mailika-key';

        $issues = $this->preflight()->installIssues();

        self::assertStringContainsString('APP_KEY must be set', implode("\n", $issues));
    }

    public function testInstallPreflightRejectsPublicStorage(): void
    {
        touch($this->root . '/.env');
        mkdir($this->root . '/public/storage', 0775, true);

        $issues = $this->preflight()->installIssues();

        self::assertStringContainsString('remove public/storage', implode("\n", $issues));
    }

    public function testUpgradePreflightDoesNotRequireEnvironmentFileOrAppKey(): void
    {
        $_ENV['APP_KEY'] = '';

        $issues = $this->preflight()->upgradeIssues();

        self::assertSame([], $issues);
    }

    public function testPreflightReportsUnavailableDatabaseDriver(): void
    {
        touch($this->root . '/.env');
        $_ENV['DB_DSN'] = 'missingdriver:host=localhost;dbname=mailika';

        $issues = $this->preflight()->installIssues();

        self::assertStringContainsString('Database driver "missingdriver" is not available', implode("\n", $issues));
    }

    public function testProductionPreflightRequiresHardenedRuntimeSettings(): void
    {
        touch($this->root . '/.env');
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['APP_URL'] = 'http://mailika.example.com';
        $_ENV['SESSION_SECURE_COOKIE'] = 'false';
        $_ENV['MAILIKA_IMAP_ADAPTER'] = 'fixture';
        $_ENV['MAILIKA_REQUIRE_TLS'] = 'false';
        $_ENV['MAILIKA_ALLOWED_IMAP_HOSTS'] = '*';
        $_ENV['MAILIKA_ALLOWED_SMTP_HOSTS'] = '*';
        $_ENV['MAILIKA_SIEVE_ENABLED'] = 'true';
        $_ENV['MAILIKA_SIEVE_REQUIRE_TLS'] = 'false';
        $_ENV['MAILIKA_ALLOWED_SIEVE_HOSTS'] = '*';
        $_ENV['MAILIKA_LDAP_ENABLED'] = 'true';
        $_ENV['MAILIKA_LDAP_REQUIRE_TLS'] = 'false';
        $_ENV['MAILIKA_ALLOWED_LDAP_HOSTS'] = '*';

        $issues = implode("\n", $this->preflight()->installIssues());

        self::assertStringContainsString('APP_DEBUG must be false in production.', $issues);
        self::assertStringContainsString('APP_URL must use https:// in production.', $issues);
        self::assertStringContainsString('SESSION_SECURE_COOKIE must be enabled in production.', $issues);
        self::assertStringContainsString('MAILIKA_IMAP_ADAPTER=fixture', $issues);
        self::assertStringContainsString('MAILIKA_REQUIRE_TLS must remain enabled in production.', $issues);
        self::assertStringContainsString('MAILIKA_ALLOWED_IMAP_HOSTS must be restricted', $issues);
        self::assertStringContainsString('MAILIKA_ALLOWED_SMTP_HOSTS must be restricted', $issues);
        self::assertStringContainsString('MAILIKA_SIEVE_REQUIRE_TLS must remain enabled', $issues);
        self::assertStringContainsString('MAILIKA_ALLOWED_SIEVE_HOSTS must be restricted', $issues);
        self::assertStringContainsString('MAILIKA_LDAP_REQUIRE_TLS must remain enabled', $issues);
        self::assertStringContainsString('MAILIKA_ALLOWED_LDAP_HOSTS must be restricted', $issues);
    }

    public function testProductionPreflightAcceptsRestrictedRuntimeSettings(): void
    {
        touch($this->root . '/.env');
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_DEBUG'] = 'false';
        $_ENV['APP_URL'] = 'https://mailika.example.com';
        $_ENV['SESSION_SECURE_COOKIE'] = 'true';
        $_ENV['MAILIKA_IMAP_ADAPTER'] = 'webklex';
        $_ENV['MAILIKA_REQUIRE_TLS'] = 'true';
        $_ENV['MAILIKA_ALLOWED_IMAP_HOSTS'] = 'imap.example.com';
        $_ENV['MAILIKA_ALLOWED_SMTP_HOSTS'] = 'smtp.example.com';
        $_ENV['MAILIKA_SIEVE_ENABLED'] = 'true';
        $_ENV['MAILIKA_SIEVE_REQUIRE_TLS'] = 'true';
        $_ENV['MAILIKA_ALLOWED_SIEVE_HOSTS'] = 'sieve.example.com';
        $_ENV['MAILIKA_LDAP_ENABLED'] = 'true';
        $_ENV['MAILIKA_LDAP_REQUIRE_TLS'] = 'true';
        $_ENV['MAILIKA_ALLOWED_LDAP_HOSTS'] = 'ldap.example.com';

        self::assertSame([], $this->preflight()->installIssues());
    }

    public function testPreflightRejectsInvalidConfiguredNetworkPorts(): void
    {
        touch($this->root . '/.env');
        $_ENV['MAILIKA_DEFAULT_IMAP_PORT'] = '0';
        $_ENV['MAILIKA_DEFAULT_SMTP_PORT'] = '70000';
        $_ENV['MAILIKA_SIEVE_ENABLED'] = 'true';
        $_ENV['MAILIKA_SIEVE_PORT'] = '-1';
        $_ENV['MAILIKA_LDAP_ENABLED'] = 'true';
        $_ENV['MAILIKA_LDAP_PORT'] = '65536';

        $issues = implode("\n", $this->preflight()->installIssues());

        self::assertStringContainsString('MAILIKA_DEFAULT_IMAP_PORT must be a valid TCP port', $issues);
        self::assertStringContainsString('MAILIKA_DEFAULT_SMTP_PORT must be a valid TCP port', $issues);
        self::assertStringContainsString('MAILIKA_SIEVE_PORT must be a valid TCP port', $issues);
        self::assertStringContainsString('MAILIKA_LDAP_PORT must be a valid TCP port', $issues);
    }

    private function configureValidEnvironment(): void
    {
        $dsn = $this->availableDsn();
        if ($dsn === null) {
            self::markTestSkipped('No PDO drivers are available for preflight tests.');
        }

        $_ENV['APP_ENV'] = 'testing';
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['DB_DSN'] = $dsn;
        $_ENV['DB_USER'] = '';
        $_ENV['DB_PASSWORD'] = '';
    }

    private function availableDsn(): ?string
    {
        $drivers = PDO::getAvailableDrivers();
        if (in_array('sqlite', $drivers, true)) {
            return 'sqlite::memory:';
        }

        if (in_array('mysql', $drivers, true)) {
            return 'mysql:host=127.0.0.1;dbname=mailika';
        }

        if (in_array('pgsql', $drivers, true)) {
            return 'pgsql:host=127.0.0.1;dbname=mailika';
        }

        return null;
    }

    private function preflight(): Preflight
    {
        return new Preflight($this->root, Config::fromEnvironment($this->root));
    }

    private function removeDirectory(string $path): void
    {
        if ($path === '' || !str_starts_with($path, sys_get_temp_dir() . '/mailika-preflight-')) {
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}

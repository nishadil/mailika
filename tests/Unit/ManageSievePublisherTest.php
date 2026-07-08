<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Filter\ManageSievePublisher;
use Mailika\Filter\SievePublishException;
use PHPUnit\Framework\TestCase;

final class ManageSievePublisherTest extends TestCase
{
    protected function tearDown(): void
    {
        $keys = [
            'MAILIKA_SIEVE_ENABLED',
            'MAILIKA_SIEVE_HOST',
            'MAILIKA_SIEVE_PORT',
            'MAILIKA_SIEVE_TLS',
            'MAILIKA_SIEVE_REQUIRE_TLS',
            'MAILIKA_ALLOWED_SIEVE_HOSTS',
        ];

        foreach ($keys as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    public function testConfiguredRejectsInvalidPort(): void
    {
        $_ENV['MAILIKA_SIEVE_ENABLED'] = 'true';
        $_ENV['MAILIKA_SIEVE_HOST'] = 'sieve.example.com';
        $_ENV['MAILIKA_SIEVE_PORT'] = '0';
        $_ENV['MAILIKA_ALLOWED_SIEVE_HOSTS'] = 'sieve.example.com';

        $publisher = new ManageSievePublisher(Config::fromEnvironment(dirname(__DIR__, 2)));

        self::assertFalse($publisher->configured());
    }

    public function testPublishRejectsInvalidPortBeforeConnecting(): void
    {
        $_ENV['MAILIKA_SIEVE_ENABLED'] = 'true';
        $_ENV['MAILIKA_SIEVE_HOST'] = 'sieve.example.com';
        $_ENV['MAILIKA_SIEVE_PORT'] = '70000';
        $_ENV['MAILIKA_SIEVE_TLS'] = 'starttls';
        $_ENV['MAILIKA_SIEVE_REQUIRE_TLS'] = 'true';
        $_ENV['MAILIKA_ALLOWED_SIEVE_HOSTS'] = 'sieve.example.com';

        $publisher = new ManageSievePublisher(Config::fromEnvironment(dirname(__DIR__, 2)));

        $this->expectException(SievePublishException::class);
        $this->expectExceptionMessage('ManageSieve port is invalid.');

        $publisher->publish($this->credentials(), "require [\"fileinto\"];\n");
    }

    private function credentials(): MailboxCredentials
    {
        return new MailboxCredentials(
            'smoke@example.com',
            'secret',
            'imap.example.com',
            993,
            true,
            'smtp.example.com',
            587,
            'starttls',
        );
    }
}

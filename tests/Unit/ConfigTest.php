<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        $keys = [
            'APP_ENV',
            'APP_DEBUG',
            'MAILIKA_DEFAULT_IMAP_PORT',
            'MAILIKA_ALLOWED_IMAP_HOSTS',
            'MAILIKA_ALLOWED_SMTP_HOSTS',
            'MAILIKA_SIEVE_ENABLED',
            'MAILIKA_SIEVE_HOST',
            'MAILIKA_SIEVE_PORT',
            'MAILIKA_SIEVE_TLS',
            'MAILIKA_ALLOWED_SIEVE_HOSTS',
            'MAILIKA_IMAP_ADAPTER',
            'MAILIKA_LDAP_ENABLED',
            'MAILIKA_LDAP_HOST',
            'MAILIKA_LDAP_PORT',
            'MAILIKA_LDAP_TLS',
            'MAILIKA_LDAP_REQUIRE_TLS',
            'MAILIKA_ALLOWED_LDAP_HOSTS',
            'MAILIKA_LDAP_BASE_DN',
            'MAILIKA_LDAP_BIND_DN',
            'MAILIKA_LDAP_BIND_PASSWORD',
            'MAILIKA_LDAP_TIMEOUT_SECONDS',
            'MAILIKA_LDAP_MAX_RESULTS',
        ];

        foreach ($keys as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    public function testReadsTypedEnvironmentValues(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['MAILIKA_DEFAULT_IMAP_PORT'] = '1993';
        $_ENV['MAILIKA_ALLOWED_IMAP_HOSTS'] = 'mail.example.com,imap.example.com';
        $_ENV['MAILIKA_ALLOWED_SMTP_HOSTS'] = 'smtp.example.com,submission.example.com';
        $_ENV['MAILIKA_SIEVE_ENABLED'] = 'true';
        $_ENV['MAILIKA_SIEVE_HOST'] = 'sieve.example.com';
        $_ENV['MAILIKA_SIEVE_PORT'] = '4190';
        $_ENV['MAILIKA_SIEVE_TLS'] = 'starttls';
        $_ENV['MAILIKA_ALLOWED_SIEVE_HOSTS'] = 'sieve.example.com';
        $_ENV['MAILIKA_LDAP_ENABLED'] = 'true';
        $_ENV['MAILIKA_LDAP_HOST'] = 'ldap.example.com';
        $_ENV['MAILIKA_LDAP_PORT'] = '636';
        $_ENV['MAILIKA_LDAP_TLS'] = 'tls';
        $_ENV['MAILIKA_LDAP_REQUIRE_TLS'] = 'true';
        $_ENV['MAILIKA_ALLOWED_LDAP_HOSTS'] = 'ldap.example.com,directory.example.com';
        $_ENV['MAILIKA_LDAP_BASE_DN'] = 'ou=people,dc=example,dc=com';
        $_ENV['MAILIKA_LDAP_BIND_DN'] = 'cn=mailika,dc=example,dc=com';
        $_ENV['MAILIKA_LDAP_BIND_PASSWORD'] = 'secret';
        $_ENV['MAILIKA_LDAP_TIMEOUT_SECONDS'] = '7';
        $_ENV['MAILIKA_LDAP_MAX_RESULTS'] = '40';

        $config = Config::fromEnvironment(dirname(__DIR__, 2));

        self::assertSame('testing', $config->string('app.env'));
        self::assertTrue($config->bool('app.debug'));
        self::assertSame(1993, $config->int('mail.default_imap_port'));
        self::assertSame(['mail.example.com', 'imap.example.com'], $config->stringList('mail.allowed_imap_hosts'));
        self::assertSame(
            ['smtp.example.com', 'submission.example.com'],
            $config->stringList('mail.allowed_smtp_hosts'),
        );
        self::assertTrue($config->bool('sieve.enabled'));
        self::assertSame('sieve.example.com', $config->string('sieve.host'));
        self::assertSame(4190, $config->int('sieve.port'));
        self::assertSame('starttls', $config->string('sieve.tls'));
        self::assertSame(['sieve.example.com'], $config->stringList('sieve.allowed_hosts'));
        self::assertTrue($config->bool('contacts.ldap.enabled'));
        self::assertSame('ldap.example.com', $config->string('contacts.ldap.host'));
        self::assertSame(636, $config->int('contacts.ldap.port'));
        self::assertSame('tls', $config->string('contacts.ldap.tls'));
        self::assertTrue($config->bool('contacts.ldap.require_tls'));
        self::assertSame(
            ['ldap.example.com', 'directory.example.com'],
            $config->stringList('contacts.ldap.allowed_hosts'),
        );
        self::assertSame('ou=people,dc=example,dc=com', $config->string('contacts.ldap.base_dn'));
        self::assertSame('cn=mailika,dc=example,dc=com', $config->string('contacts.ldap.bind_dn'));
        self::assertSame('secret', $config->string('contacts.ldap.bind_password'));
        self::assertSame(7, $config->int('contacts.ldap.timeout_seconds'));
        self::assertSame(40, $config->int('contacts.ldap.max_results'));
    }

    public function testUsesDefaultsWhenEnvironmentValueIsMissing(): void
    {
        unset($_ENV['MAILIKA_IMAP_ADAPTER'], $_SERVER['MAILIKA_IMAP_ADAPTER']);
        putenv('MAILIKA_IMAP_ADAPTER');

        $config = Config::fromEnvironment(dirname(__DIR__, 2));

        self::assertSame('webklex', $config->string('mail.imap_adapter'));
    }
}

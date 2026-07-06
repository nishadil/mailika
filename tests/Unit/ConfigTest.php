<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testReadsTypedEnvironmentValues(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['MAILIKA_DEFAULT_IMAP_PORT'] = '1993';
        $_ENV['MAILIKA_ALLOWED_IMAP_HOSTS'] = 'mail.example.com,imap.example.com';

        $config = Config::fromEnvironment(dirname(__DIR__, 2));

        self::assertSame('testing', $config->string('app.env'));
        self::assertTrue($config->bool('app.debug'));
        self::assertSame(1993, $config->int('mail.default_imap_port'));
        self::assertSame(['mail.example.com', 'imap.example.com'], $config->stringList('mail.allowed_imap_hosts'));
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Cache\RedisConnectorInterface;
use Mailika\Config\Config;
use Mailika\Controller\HealthController;
use Mailika\Database\Connection;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Module\CoreModules;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $_ENV['APP_KEY'],
            $_ENV['APP_ENV'],
            $_ENV['DB_DSN'],
            $_ENV['DB_PASSWORD'],
            $_ENV['MAILIKA_RATE_LIMIT_STORE'],
            $_ENV['REDIS_DSN'],
            $_ENV['REDIS_SESSION_DSN'],
            $_ENV['SESSION_DRIVER'],
        );
    }

    public function testMetricsExposeOperationalStatusWithoutSecrets(): void
    {
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('h', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['DB_DSN'] = 'pgsql:host=secret-db;dbname=mailika';
        $_ENV['DB_PASSWORD'] = 'super-secret';
        $config = Config::fromEnvironment(dirname(__DIR__, 2));

        $controller = new HealthController(
            $config,
            new Connection($config),
            CoreModules::fromConfig($config),
        );

        $content = $this->send($controller->metrics(new Request('GET', '/metrics', [], [], [], [], [])));

        self::assertStringContainsString('mailika_build_info{', $content);
        self::assertStringContainsString('environment="testing"', $content);
        self::assertStringContainsString('mailika_ready ', $content);
        self::assertStringContainsString('mailika_check_php_supported ', $content);
        self::assertStringNotContainsString('super-secret', $content);
        self::assertStringNotContainsString('secret-db', $content);
    }

    public function testReadyDegradesWhenRedisSessionDriverIsConfiguredWithoutExtension(): void
    {
        if (extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is available in this runtime.');
        }

        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('h', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['SESSION_DRIVER'] = 'redis';

        $config = Config::fromEnvironment(dirname(__DIR__, 2));
        $controller = new HealthController($config, new Connection($config), CoreModules::fromConfig($config));

        $content = $this->send($controller->ready(new Request('GET', '/readyz', [], [], [], [], [])));

        self::assertStringContainsString('"status":"degraded"', $content);
        self::assertStringContainsString('"session_redis_available":false', $content);
        self::assertStringContainsString('"session_redis_reachable":false', $content);
        self::assertStringContainsString('"internal_modules":"auth,mail,compose', $content);
    }

    public function testReadyChecksRedisReachabilityWhenRedisStoresAreConfigured(): void
    {
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('h', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['SESSION_DRIVER'] = 'redis';
        $_ENV['MAILIKA_RATE_LIMIT_STORE'] = 'redis';
        $_ENV['REDIS_DSN'] = 'redis://rate.example.com:6379/1';
        $_ENV['REDIS_SESSION_DSN'] = 'tcp://session.example.com:6379?database=2';

        $config = Config::fromEnvironment(dirname(__DIR__, 2));
        $controller = new HealthController(
            $config,
            new Connection($config),
            CoreModules::fromConfig($config),
            new class implements RedisConnectorInterface {
                public function available(): bool
                {
                    return true;
                }

                public function ping(string $dsn): bool
                {
                    return str_contains($dsn, 'rate.example.com');
                }

                public function incrementWithTtl(string $dsn, string $key, int $ttlSeconds): int
                {
                    return 1;
                }
            },
        );

        $content = $this->send($controller->ready(new Request('GET', '/readyz', [], [], [], [], [])));

        self::assertStringContainsString('"status":"degraded"', $content);
        self::assertStringContainsString('"session_redis_available":true', $content);
        self::assertStringContainsString('"session_redis_reachable":false', $content);
        self::assertStringContainsString('"rate_limit_redis_available":true', $content);
        self::assertStringContainsString('"rate_limit_redis_reachable":true', $content);
        self::assertStringNotContainsString('rate.example.com', $content);
        self::assertStringNotContainsString('session.example.com', $content);
    }

    private function send(Response $response): string
    {
        ob_start();
        $response->send();
        return (string) ob_get_clean();
    }
}

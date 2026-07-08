<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Cache\RedisConnectorInterface;
use Mailika\Config\Config;
use Mailika\Security\RateLimiter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RateLimiterTest extends TestCase
{
    private string $cachePath = '';

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . '/mailika-rate-limiter-' . bin2hex(random_bytes(4));
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['MAILIKA_RATE_LIMIT_STORE'] = 'redis';
        $_ENV['REDIS_DSN'] = 'redis://cache.example.com:6379/7';
    }

    protected function tearDown(): void
    {
        foreach (['APP_ENV', 'MAILIKA_RATE_LIMIT_STORE', 'REDIS_DSN'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        if (is_dir($this->cachePath)) {
            rmdir($this->cachePath);
        }
    }

    public function testRedisStoreAllowsUntilLimitIsExceeded(): void
    {
        $redis = new class implements RedisConnectorInterface {
            /**
             * @var array<string, int>
             */
            public array $increments = [];

            public function available(): bool
            {
                return true;
            }

            public function ping(string $dsn): bool
            {
                return true;
            }

            public function incrementWithTtl(string $dsn, string $key, int $ttlSeconds): int
            {
                $this->increments[$key] = ($this->increments[$key] ?? 0) + 1;
                return $this->increments[$key];
            }
        };

        $limiter = new RateLimiter($this->cachePath, $this->config(), $redis);

        self::assertTrue($limiter->allow('login|203.0.113.10|user@example.com', 2, 300));
        self::assertTrue($limiter->allow('login|203.0.113.10|user@example.com', 2, 300));
        self::assertFalse($limiter->allow('login|203.0.113.10|user@example.com', 2, 300));
    }

    public function testRedisStoreFailsClosedWhenExtensionIsUnavailable(): void
    {
        $redis = new class implements RedisConnectorInterface {
            public function available(): bool
            {
                return false;
            }

            public function ping(string $dsn): bool
            {
                return false;
            }

            public function incrementWithTtl(string $dsn, string $key, int $ttlSeconds): int
            {
                throw new RuntimeException('should not be called');
            }
        };

        $limiter = new RateLimiter($this->cachePath, $this->config(), $redis);

        self::assertFalse($limiter->allow('login|203.0.113.10|user@example.com', 2, 300));
    }

    public function testRedisStoreFailsClosedWhenConnectorThrows(): void
    {
        $redis = new class implements RedisConnectorInterface {
            public function available(): bool
            {
                return true;
            }

            public function ping(string $dsn): bool
            {
                return false;
            }

            public function incrementWithTtl(string $dsn, string $key, int $ttlSeconds): int
            {
                throw new RuntimeException('Redis unavailable');
            }
        };

        $limiter = new RateLimiter($this->cachePath, $this->config(), $redis);

        self::assertFalse($limiter->allow('login|203.0.113.10|user@example.com', 2, 300));
    }

    private function config(): Config
    {
        return Config::fromEnvironment(dirname(__DIR__, 2));
    }
}

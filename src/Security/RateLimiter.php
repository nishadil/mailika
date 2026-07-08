<?php

declare(strict_types=1);

namespace Mailika\Security;

use Mailika\Cache\RedisConnector;
use Mailika\Cache\RedisConnectorInterface;
use Mailika\Config\Config;
use Throwable;

final readonly class RateLimiter
{
    public function __construct(
        private string $cachePath,
        private ?Config $config = null,
        private ?RedisConnectorInterface $redis = null,
    ) {
    }

    public function allow(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        if ($this->config?->string('rate_limit.store') === 'redis') {
            return $this->allowRedis($key, $maxAttempts, $windowSeconds);
        }

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0775, true);
        }

        $file = $this->cachePath . '/rate-' . hash('sha256', $key) . '.json';
        $now = time();
        $attempts = [];

        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $attempts = array_values(array_filter(
                    array_map('intval', $decoded),
                    static fn (int $timestamp): bool => $timestamp > $now - $windowSeconds,
                ));
            }
        }

        if (count($attempts) >= $maxAttempts) {
            return false;
        }

        $attempts[] = $now;
        file_put_contents($file, json_encode($attempts, JSON_THROW_ON_ERROR), LOCK_EX);

        return true;
    }

    private function allowRedis(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $connector = $this->redis ?? new RedisConnector();
        if (!$connector->available()) {
            return false;
        }

        try {
            $attempts = $connector->incrementWithTtl(
                $this->config?->string('redis.dsn', 'redis://127.0.0.1:6379/0') ?? '',
                'mailika:rate:' . hash('sha256', $key),
                $windowSeconds,
            );
        } catch (Throwable) {
            return false;
        }

        return $attempts <= $maxAttempts;
    }
}

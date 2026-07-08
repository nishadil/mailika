<?php

declare(strict_types=1);

namespace Mailika\Cache;

use RuntimeException;
use Throwable;

final readonly class RedisConnector implements RedisConnectorInterface
{
    public function available(): bool
    {
        return extension_loaded('redis') && class_exists('Redis');
    }

    public function ping(string $dsn): bool
    {
        if (!$this->available()) {
            return false;
        }

        try {
            $redis = $this->connect($dsn);
            $this->call($redis, 'ping');
            $this->call($redis, 'close');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function incrementWithTtl(string $dsn, string $key, int $ttlSeconds): int
    {
        if (!$this->available()) {
            throw new RuntimeException('Redis extension is not available.');
        }

        $redis = $this->connect($dsn);
        try {
            $attempts = (int) $this->call($redis, 'incr', $key);
            if ($attempts === 1) {
                $this->call($redis, 'expire', $key, $ttlSeconds);
            }

            return $attempts;
        } finally {
            $this->call($redis, 'close');
        }
    }

    public function options(string $dsn): RedisConnectionOptions
    {
        $parts = parse_url($dsn);
        if (!is_array($parts)) {
            throw new RuntimeException('Redis DSN is invalid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'redis'));
        if (!in_array($scheme, ['redis', 'rediss', 'tcp', 'tls'], true)) {
            throw new RuntimeException('Redis DSN must use redis, rediss, tcp, or tls.');
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host) || $host === '') {
            throw new RuntimeException('Redis DSN must include a host.');
        }

        $query = $this->query($parts);

        $timeout = $query['timeout'] ?? 1.5;
        $database = $this->database($parts, $query);

        return new RedisConnectionOptions(
            ($scheme === 'rediss' || $scheme === 'tls') ? 'tls://' . $host : $host,
            isset($parts['port']) ? (int) $parts['port'] : 6379,
            is_numeric($timeout) ? max((float) $timeout, 0.1) : 1.5,
            $database,
            $this->scalarString($parts['user'] ?? null),
            $this->scalarString($parts['pass'] ?? null),
        );
    }

    private function connect(string $dsn): object
    {
        $redisClass = 'Redis';
        $redis = new $redisClass();
        $options = $this->options($dsn);

        $this->call($redis, 'connect', $options->host, $options->port, $options->timeoutSeconds);

        if ($options->password !== null && $options->password !== '') {
            $auth = $options->username === null || $options->username === ''
                ? $options->password
                : [$options->username, $options->password];
            $this->call($redis, 'auth', $auth);
        }

        if ($options->database !== null) {
            $this->call($redis, 'select', $options->database);
        }

        return $redis;
    }

    private function call(object $redis, string $method, mixed ...$arguments): mixed
    {
        if (!method_exists($redis, $method)) {
            throw new RuntimeException('Redis method is not available: ' . $method);
        }

        return $redis->{$method}(...$arguments);
    }

    /**
     * @param array<string, mixed> $parts
     * @return array<string, mixed>
     */
    private function query(array $parts): array
    {
        if (!isset($parts['query'])) {
            return [];
        }

        $parsed = [];
        parse_str((string) $parts['query'], $parsed);

        $query = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key)) {
                $query[$key] = $value;
            }
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $parts
     * @param array<string, mixed> $query
     */
    private function database(array $parts, array $query): ?int
    {
        $database = $query['database'] ?? null;
        if ($database === null && isset($parts['path']) && is_string($parts['path'])) {
            $database = trim($parts['path'], '/');
        }

        return is_numeric($database) ? (int) $database : null;
    }

    private function scalarString(mixed $value): ?string
    {
        if (!is_scalar($value) || $value === '') {
            return null;
        }

        return rawurldecode((string) $value);
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Cache\RedisConnector;
use Mailika\Cache\RedisConnectorInterface;
use Mailika\Config\Config;
use Mailika\Database\Connection;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Module\InternalModuleRegistry;

final readonly class HealthController
{
    public function __construct(
        private Config $config,
        private Connection $connection,
        private InternalModuleRegistry $modules,
        private ?RedisConnectorInterface $redis = null,
    ) {
    }

    public function show(Request $request): Response
    {
        return Response::json([
            'status' => 'ok',
            'service' => 'mailika',
            'environment' => $this->config->string('app.env'),
            'php' => PHP_VERSION,
        ]);
    }

    public function ready(Request $request): Response
    {
        $status = $this->readiness();

        return Response::json([
            'status' => $status['ready'] ? 'ok' : 'degraded',
            'service' => 'mailika',
            'environment' => $this->config->string('app.env'),
            'checks' => $status['checks'],
        ], $status['ready'] ? 200 : 503);
    }

    public function metrics(Request $request): Response
    {
        $status = $this->readiness();
        $checks = $status['checks'];
        $labels = [
            'service' => 'mailika',
            'environment' => $this->config->string('app.env'),
            'php_version' => PHP_VERSION,
            'imap_adapter' => $this->config->string('mail.imap_adapter'),
            'data_store' => (string) $checks['data_store'],
            'session_driver' => (string) $checks['session_driver'],
            'rate_limit_store' => (string) $checks['rate_limit_store'],
        ];

        $lines = [
            '# HELP mailika_build_info Mailika runtime information.',
            '# TYPE mailika_build_info gauge',
            'mailika_build_info{' . $this->labels($labels) . '} 1',
            '# HELP mailika_ready Mailika readiness status, 1 for ready and 0 for degraded.',
            '# TYPE mailika_ready gauge',
            'mailika_ready ' . ($status['ready'] ? '1' : '0'),
        ];

        foreach ($checks as $name => $value) {
            if (is_bool($value)) {
                $lines[] = '# TYPE mailika_check_' . $name . ' gauge';
                $lines[] = 'mailika_check_' . $name . ' ' . ($value ? '1' : '0');
            }
        }

        return new Response(
            implode("\n", $lines) . "\n",
            200,
            ['Content-Type' => 'text/plain; version=0.0.4; charset=UTF-8'],
        );
    }

    private function writablePath(string $path): bool
    {
        $fullPath = $this->config->rootPath($path);
        return is_dir($fullPath) && is_writable($fullPath);
    }

    /**
     * @return array{ready:bool,checks:array<string,bool|string>}
     */
    private function readiness(): array
    {
        $redis = $this->redis ?? new RedisConnector();
        $sessionUsesRedis = $this->config->string('session.driver') === 'redis';
        $rateLimitUsesRedis = $this->config->string('rate_limit.store') === 'redis';
        $checks = [
            'php_supported' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'data_store' => $this->config->string('data.store'),
            'database_driver_available' => $this->connection->driverAvailable(),
            'storage_cache_writable' => $this->writablePath('storage/cache'),
            'storage_logs_writable' => $this->writablePath('storage/logs'),
            'session_driver' => $this->config->string('session.driver'),
            'session_redis_available' => !$sessionUsesRedis || $redis->available(),
            'session_redis_reachable' => !$sessionUsesRedis || $redis->ping($this->config->string('redis.session_dsn')),
            'rate_limit_store' => $this->config->string('rate_limit.store'),
            'rate_limit_redis_available' => !$rateLimitUsesRedis || $redis->available(),
            'rate_limit_redis_reachable' => !$rateLimitUsesRedis || $redis->ping($this->config->string('redis.dsn')),
            'imap_adapter' => $this->config->string('mail.imap_adapter'),
            'internal_modules' => implode(',', $this->modules->enabledNames()),
        ];

        $ready = $checks['php_supported']
            && ($checks['data_store'] !== 'database' || $checks['database_driver_available'])
            && $checks['storage_cache_writable']
            && $checks['storage_logs_writable']
            && $checks['session_redis_available']
            && $checks['session_redis_reachable']
            && $checks['rate_limit_redis_available']
            && $checks['rate_limit_redis_reachable'];

        return ['ready' => $ready, 'checks' => $checks];
    }

    /**
     * @param array<string, string> $labels
     */
    private function labels(array $labels): string
    {
        $parts = [];
        foreach ($labels as $name => $value) {
            $parts[] = $name . '="' . $this->labelValue($value) . '"';
        }

        return implode(',', $parts);
    }

    private function labelValue(string $value): string
    {
        return str_replace(["\\", "\n", '"'], ["\\\\", "\\n", "\\\""], $value);
    }
}

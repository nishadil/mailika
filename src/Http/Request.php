<?php

declare(strict_types=1);

namespace Mailika\Http;

final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     * @param array<string, string> $cookies
     * @param array<string, mixed> $server
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $body,
        public array $files,
        public array $cookies,
        public array $server,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            is_string($path) && $path !== '' ? $path : '/',
            $_GET,
            $_POST,
            $_FILES,
            array_map('strval', $_COOKIE),
            $_SERVER,
        );
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? $default;
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function intInput(string $key, int $default = 0): int
    {
        $value = $this->input($key, (string) $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function ip(): string
    {
        $value = $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
        return is_scalar($value) ? (string) $value : '127.0.0.1';
    }

    public function userAgent(): string
    {
        $value = $this->server['HTTP_USER_AGENT'] ?? '';
        return is_scalar($value) ? substr((string) $value, 0, 512) : '';
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? 'off';
        $forwarded = $this->server['HTTP_X_FORWARDED_PROTO'] ?? '';

        return $https === 'on' || $https === '1' || $forwarded === 'https';
    }
}

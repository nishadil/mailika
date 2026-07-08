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

    /**
     * @return list<string>
     */
    public function inputList(string $key): array
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? [];
        if (is_scalar($value)) {
            $item = trim((string) $value);
            return $item === '' ? [] : [$item];
        }

        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $item = trim((string) $item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    public function intInput(string $key, int $default = 0): int
    {
        $value = $this->input($key, (string) $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function uploadedFiles(string $key): array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file)) {
            return [];
        }

        if (!is_array($file['name'] ?? null)) {
            return [$file];
        }

        $uploads = [];
        foreach (array_keys($file['name']) as $index) {
            $uploads[] = [
                'name' => self::fileField($file, 'name', $index, ''),
                'type' => self::fileField($file, 'type', $index, ''),
                'tmp_name' => self::fileField($file, 'tmp_name', $index, ''),
                'error' => self::fileField($file, 'error', $index, UPLOAD_ERR_NO_FILE),
                'size' => self::fileField($file, 'size', $index, 0),
            ];
        }

        return $uploads;
    }

    /**
     * @param array<string, mixed> $file
     */
    private static function fileField(array $file, string $field, int|string $index, mixed $default): mixed
    {
        $values = $file[$field] ?? null;
        if (!is_array($values) || !array_key_exists($index, $values)) {
            return $default;
        }

        return $values[$index];
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

    /**
     * @param list<string> $trustedProxies
     */
    public function isSecure(array $trustedProxies = []): bool
    {
        $https = $this->server['HTTPS'] ?? 'off';
        if ($https === 'on' || $https === '1') {
            return true;
        }

        if (!$this->remoteAddressTrusted($this->ip(), $trustedProxies)) {
            return false;
        }

        $forwarded = $this->server['HTTP_X_FORWARDED_PROTO'] ?? '';
        $proto = is_scalar($forwarded) ? strtolower(trim(explode(',', (string) $forwarded)[0])) : '';

        return $proto === 'https';
    }

    /**
     * @param list<string> $trustedProxies
     */
    private function remoteAddressTrusted(string $remoteAddress, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $trustedProxy) {
            if ($trustedProxy === '*') {
                return true;
            }

            if (hash_equals($trustedProxy, $remoteAddress)) {
                return true;
            }

            if (str_contains($trustedProxy, '/') && self::ipMatchesCidr($remoteAddress, $trustedProxy)) {
                return true;
            }
        }

        return false;
    }

    private static function ipMatchesCidr(string $ipAddress, string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($network === null || $prefix === null || !is_numeric($prefix)) {
            return false;
        }

        $ipBytes = inet_pton($ipAddress);
        $networkBytes = inet_pton($network);
        if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefixLength = (int) $prefix;
        $bits = strlen($ipBytes) * 8;
        if ($prefixLength < 0 || $prefixLength > $bits) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($ipBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($ipBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }
}

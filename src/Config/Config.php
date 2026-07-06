<?php

declare(strict_types=1);

namespace Mailika\Config;

final readonly class Config
{
    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private array $values)
    {
    }

    public static function fromEnvironment(string $root): self
    {
        return new self([
            'app.root' => $root,
            'app.env' => self::env('APP_ENV', 'production'),
            'app.debug' => self::boolEnv('APP_DEBUG', false),
            'app.url' => rtrim(self::env('APP_URL', 'http://127.0.0.1:8080'), '/'),
            'app.key' => self::env('APP_KEY', ''),
            'session.name' => self::env('SESSION_NAME', 'mailika_session'),
            'session.lifetime_seconds' => self::intEnv('SESSION_LIFETIME_SECONDS', 1800),
            'session.secure_cookie' => self::boolEnv('SESSION_SECURE_COOKIE', true),
            'database.dsn' => self::env('DB_DSN', 'pgsql:host=postgres;port=5432;dbname=mailika'),
            'database.user' => self::env('DB_USER', 'mailika'),
            'database.password' => self::env('DB_PASSWORD', ''),
            'mail.allowed_imap_hosts' => self::csvEnv('MAILIKA_ALLOWED_IMAP_HOSTS', ['*']),
            'mail.default_imap_host' => self::env('MAILIKA_DEFAULT_IMAP_HOST', ''),
            'mail.default_imap_port' => self::intEnv('MAILIKA_DEFAULT_IMAP_PORT', 993),
            'mail.default_imap_tls' => self::boolEnv('MAILIKA_DEFAULT_IMAP_TLS', true),
            'mail.default_smtp_host' => self::env('MAILIKA_DEFAULT_SMTP_HOST', ''),
            'mail.default_smtp_port' => self::intEnv('MAILIKA_DEFAULT_SMTP_PORT', 587),
            'mail.default_smtp_tls' => self::env('MAILIKA_DEFAULT_SMTP_TLS', 'starttls'),
            'mail.imap_validate_login' => self::boolEnv('MAILIKA_IMAP_VALIDATE_LOGIN', true),
            'mail.max_attachment_bytes' => self::intEnv('MAILIKA_MAX_ATTACHMENT_BYTES', 26_214_400),
            'mail.remote_images' => self::boolEnv('MAILIKA_REMOTE_IMAGES', false),
            'mail.require_tls' => self::boolEnv('MAILIKA_REQUIRE_TLS', true),
            'rate_limit.login_attempts' => self::intEnv('MAILIKA_RATE_LIMIT_LOGIN_ATTEMPTS', 8),
            'rate_limit.login_window_seconds' => self::intEnv('MAILIKA_RATE_LIMIT_LOGIN_WINDOW_SECONDS', 300),
            'log.channel' => self::env('LOG_CHANNEL', 'file'),
            'log.path' => self::env('LOG_PATH', $root . '/storage/logs/mailika.log'),
        ]);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? $default;
        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? $default;
        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->values[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value)));
    }

    public function rootPath(string $path = ''): string
    {
        $root = $this->string('app.root');
        return $path === '' ? $root : $root . '/' . ltrim($path, '/');
    }

    public function isProduction(): bool
    {
        return $this->string('app.env') === 'production';
    }

    private static function env(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return is_scalar($value) && $value !== '' ? (string) $value : $default;
    }

    private static function intEnv(string $key, int $default): int
    {
        $value = self::env($key, (string) $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function boolEnv(string $key, bool $default): bool
    {
        $value = self::env($key, $default ? 'true' : 'false');
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private static function csvEnv(string $key, array $default): array
    {
        $value = self::env($key, implode(',', $default));
        $items = array_values(array_filter(array_map('trim', explode(',', $value))));
        return $items === [] ? $default : $items;
    }
}

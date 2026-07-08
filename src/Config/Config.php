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
        $appEnv = self::env('APP_ENV', 'production');

        return new self([
            'app.root' => $root,
            'app.env' => $appEnv,
            'app.debug' => self::boolEnv('APP_DEBUG', false),
            'app.url' => rtrim(self::env('APP_URL', 'http://127.0.0.1:8080'), '/'),
            'app.key' => self::env('APP_KEY', ''),
            'http.trusted_proxies' => self::csvEnv('MAILIKA_TRUSTED_PROXIES', []),
            'session.name' => self::env('SESSION_NAME', 'mailika_session'),
            'session.lifetime_seconds' => self::intEnv('SESSION_LIFETIME_SECONDS', 1800),
            'session.secure_cookie' => self::boolEnv('SESSION_SECURE_COOKIE', true),
            'session.driver' => self::env('SESSION_DRIVER', 'file'),
            'database.dsn' => self::env('DB_DSN', 'pgsql:host=postgres;port=5432;dbname=mailika'),
            'database.user' => self::env('DB_USER', 'mailika'),
            'database.password' => self::env('DB_PASSWORD', ''),
            'data.store' => self::env('MAILIKA_DATA_STORE', $appEnv === 'production' ? 'database' : 'auto'),
            'mail.allowed_imap_hosts' => self::csvEnv('MAILIKA_ALLOWED_IMAP_HOSTS', ['*']),
            'mail.allowed_smtp_hosts' => self::csvEnv('MAILIKA_ALLOWED_SMTP_HOSTS', ['*']),
            'mail.imap_adapter' => self::env('MAILIKA_IMAP_ADAPTER', 'webklex'),
            'mail.default_imap_host' => self::env('MAILIKA_DEFAULT_IMAP_HOST', ''),
            'mail.default_imap_port' => self::intEnv('MAILIKA_DEFAULT_IMAP_PORT', 993),
            'mail.default_imap_tls' => self::boolEnv('MAILIKA_DEFAULT_IMAP_TLS', true),
            'mail.default_smtp_host' => self::env('MAILIKA_DEFAULT_SMTP_HOST', ''),
            'mail.default_smtp_port' => self::intEnv('MAILIKA_DEFAULT_SMTP_PORT', 587),
            'mail.default_smtp_tls' => self::env('MAILIKA_DEFAULT_SMTP_TLS', 'starttls'),
            'mail.imap_validate_login' => self::boolEnv('MAILIKA_IMAP_VALIDATE_LOGIN', true),
            'mail.max_attachment_bytes' => self::intEnv('MAILIKA_MAX_ATTACHMENT_BYTES', 26_214_400),
            'mail.max_attachments' => self::intEnv('MAILIKA_MAX_ATTACHMENTS', 10),
            'mail.max_attachment_total_bytes' => self::intEnv('MAILIKA_MAX_ATTACHMENT_TOTAL_BYTES', 52_428_800),
            'mail.remote_images' => self::boolEnv('MAILIKA_REMOTE_IMAGES', false),
            'mail.require_tls' => self::boolEnv('MAILIKA_REQUIRE_TLS', true),
            'contacts.ldap.enabled' => self::boolEnv('MAILIKA_LDAP_ENABLED', false),
            'contacts.ldap.host' => self::env('MAILIKA_LDAP_HOST', ''),
            'contacts.ldap.port' => self::intEnv('MAILIKA_LDAP_PORT', 389),
            'contacts.ldap.tls' => self::env('MAILIKA_LDAP_TLS', 'starttls'),
            'contacts.ldap.require_tls' => self::boolEnv('MAILIKA_LDAP_REQUIRE_TLS', true),
            'contacts.ldap.allowed_hosts' => self::csvEnv('MAILIKA_ALLOWED_LDAP_HOSTS', ['*']),
            'contacts.ldap.base_dn' => self::env('MAILIKA_LDAP_BASE_DN', ''),
            'contacts.ldap.bind_dn' => self::env('MAILIKA_LDAP_BIND_DN', ''),
            'contacts.ldap.bind_password' => self::env('MAILIKA_LDAP_BIND_PASSWORD', ''),
            'contacts.ldap.timeout_seconds' => self::intEnv('MAILIKA_LDAP_TIMEOUT_SECONDS', 5),
            'contacts.ldap.max_results' => self::intEnv('MAILIKA_LDAP_MAX_RESULTS', 25),
            'sieve.enabled' => self::boolEnv('MAILIKA_SIEVE_ENABLED', false),
            'sieve.host' => self::env('MAILIKA_SIEVE_HOST', ''),
            'sieve.port' => self::intEnv('MAILIKA_SIEVE_PORT', 4190),
            'sieve.tls' => self::env('MAILIKA_SIEVE_TLS', 'starttls'),
            'sieve.require_tls' => self::boolEnv('MAILIKA_SIEVE_REQUIRE_TLS', true),
            'sieve.allowed_hosts' => self::csvEnv('MAILIKA_ALLOWED_SIEVE_HOSTS', ['*']),
            'sieve.script_name' => self::env('MAILIKA_SIEVE_SCRIPT_NAME', 'mailika'),
            'sieve.timeout_seconds' => self::intEnv('MAILIKA_SIEVE_TIMEOUT_SECONDS', 10),
            'rate_limit.login_attempts' => self::intEnv('MAILIKA_RATE_LIMIT_LOGIN_ATTEMPTS', 8),
            'rate_limit.login_window_seconds' => self::intEnv('MAILIKA_RATE_LIMIT_LOGIN_WINDOW_SECONDS', 300),
            'rate_limit.store' => self::env('MAILIKA_RATE_LIMIT_STORE', 'file'),
            'redis.dsn' => self::env('REDIS_DSN', 'redis://redis:6379/0'),
            'redis.session_dsn' => self::env('REDIS_SESSION_DSN', 'tcp://redis:6379?database=0'),
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
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if (is_scalar($value) && $value !== '') {
            return (string) $value;
        }

        $environmentValue = getenv($key);
        return is_string($environmentValue) && $environmentValue !== '' ? $environmentValue : $default;
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

<?php

declare(strict_types=1);

namespace Mailika\Install;

use Mailika\Config\Config;
use Mailika\Validation\Validator;
use PDO;

final readonly class Preflight
{
    /**
     * @var list<string>
     */
    private const REQUIRED_EXTENSIONS = [
        'ctype',
        'dom',
        'fileinfo',
        'filter',
        'iconv',
        'json',
        'libxml',
        'mbstring',
        'openssl',
        'pdo',
        'sodium',
        'zip',
    ];

    /**
     * @var list<string>
     */
    private const STORAGE_DIRECTORIES = [
        'storage/cache',
        'storage/logs',
        'storage/sessions',
        'storage/uploads',
    ];

    public function __construct(private string $root, private Config $config)
    {
    }

    /**
     * @return list<string>
     */
    public function installIssues(): array
    {
        return array_merge(
            $this->environmentFileIssues(),
            $this->commonIssues(),
            $this->appKeyIssues(),
            $this->databaseDriverIssues(),
        );
    }

    /**
     * @return list<string>
     */
    public function upgradeIssues(): array
    {
        return array_merge(
            $this->commonIssues(),
            $this->databaseDriverIssues(),
        );
    }

    /**
     * @return list<string>
     */
    private function environmentFileIssues(): array
    {
        if (is_file($this->root . '/.env')) {
            return [];
        }

        return ['.env is missing. Copy .env.example, set APP_KEY, and configure the database before installing.'];
    }

    /**
     * @return list<string>
     */
    private function commonIssues(): array
    {
        $issues = [];

        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            $issues[] = 'PHP 8.3 or newer is required.';
        }

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!extension_loaded($extension)) {
                $issues[] = sprintf('Missing required PHP extension: %s.', $extension);
            }
        }

        if (is_dir($this->root . '/public/storage')) {
            $issues[] = 'Writable paths must stay outside public/; remove public/storage.';
        }

        foreach (self::STORAGE_DIRECTORIES as $directory) {
            $path = $this->root . '/' . $directory;
            if (!is_dir($path) && !@mkdir($path, 0775, true)) {
                $issues[] = sprintf('Unable to create writable storage directory: %s.', $directory);
                continue;
            }

            if (!is_writable($path)) {
                $issues[] = sprintf('Storage directory is not writable: %s.', $directory);
            }
        }

        return array_merge($issues, $this->productionIssues(), $this->networkPortIssues());
    }

    /**
     * @return list<string>
     */
    private function appKeyIssues(): array
    {
        $appKey = trim($this->config->string('app.key'));
        if ($appKey === '' || str_contains($appKey, 'replace-with')) {
            return ['APP_KEY must be set. Generate one with `php bin/mailika-key`.'];
        }

        if (!str_starts_with($appKey, 'base64:')) {
            return ['APP_KEY must start with base64: and contain a 32-byte Sodium secretbox key.'];
        }

        $decoded = base64_decode(substr($appKey, 7), true);
        if (!is_string($decoded)) {
            return ['APP_KEY must contain valid base64 data after the base64: prefix.'];
        }

        if (
            defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES')
            && strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        ) {
            return ['APP_KEY must decode to a 32-byte Sodium secretbox key.'];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function databaseDriverIssues(): array
    {
        $driver = strstr($this->config->string('database.dsn'), ':', true);
        if (!is_string($driver) || $driver === '') {
            return ['DB_DSN must begin with a PDO driver prefix such as pgsql:, mysql:, or sqlite:.'];
        }

        if (!in_array(strtolower($driver), PDO::getAvailableDrivers(), true)) {
            return [
                sprintf(
                    'Database driver "%s" is not available to PDO. Install the matching PDO extension.',
                    $driver,
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function productionIssues(): array
    {
        if (!$this->config->isProduction()) {
            return [];
        }

        $issues = [];

        if ($this->config->bool('app.debug')) {
            $issues[] = 'APP_DEBUG must be false in production.';
        }

        if (!str_starts_with($this->config->string('app.url'), 'https://')) {
            $issues[] = 'APP_URL must use https:// in production.';
        }

        if (!$this->config->bool('session.secure_cookie')) {
            $issues[] = 'SESSION_SECURE_COOKIE must be enabled in production.';
        }

        if ($this->config->string('mail.imap_adapter') === 'fixture') {
            $issues[] = 'MAILIKA_IMAP_ADAPTER=fixture is for local tests only and cannot be used in production.';
        }

        if (!$this->config->bool('mail.require_tls')) {
            $issues[] = 'MAILIKA_REQUIRE_TLS must remain enabled in production.';
        }

        if (in_array('*', $this->config->stringList('mail.allowed_imap_hosts'), true)) {
            $issues[] = 'MAILIKA_ALLOWED_IMAP_HOSTS must be restricted in production.';
        }

        if (in_array('*', $this->config->stringList('mail.allowed_smtp_hosts'), true)) {
            $issues[] = 'MAILIKA_ALLOWED_SMTP_HOSTS must be restricted in production.';
        }

        if ($this->config->bool('sieve.enabled')) {
            if (!$this->config->bool('sieve.require_tls')) {
                $issues[] = 'MAILIKA_SIEVE_REQUIRE_TLS must remain enabled in production.';
            }

            if (in_array('*', $this->config->stringList('sieve.allowed_hosts'), true)) {
                $issues[] = 'MAILIKA_ALLOWED_SIEVE_HOSTS must be restricted when ManageSieve is enabled.';
            }
        }

        if ($this->config->bool('contacts.ldap.enabled')) {
            if (!$this->config->bool('contacts.ldap.require_tls')) {
                $issues[] = 'MAILIKA_LDAP_REQUIRE_TLS must remain enabled in production.';
            }

            if (in_array('*', $this->config->stringList('contacts.ldap.allowed_hosts'), true)) {
                $issues[] = 'MAILIKA_ALLOWED_LDAP_HOSTS must be restricted when LDAP is enabled.';
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function networkPortIssues(): array
    {
        $ports = [
            'MAILIKA_DEFAULT_IMAP_PORT' => $this->config->int('mail.default_imap_port', 993),
            'MAILIKA_DEFAULT_SMTP_PORT' => $this->config->int('mail.default_smtp_port', 587),
        ];

        if ($this->config->bool('sieve.enabled')) {
            $ports['MAILIKA_SIEVE_PORT'] = $this->config->int('sieve.port', 4190);
        }

        if ($this->config->bool('contacts.ldap.enabled')) {
            $ports['MAILIKA_LDAP_PORT'] = $this->config->int('contacts.ldap.port', 389);
        }

        $issues = [];
        foreach ($ports as $name => $port) {
            if (!Validator::tcpPort($port)) {
                $issues[] = sprintf('%s must be a valid TCP port between 1 and 65535.', $name);
            }
        }

        return $issues;
    }
}

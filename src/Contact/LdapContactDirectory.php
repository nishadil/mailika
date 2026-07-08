<?php

declare(strict_types=1);

namespace Mailika\Contact;

use Mailika\Config\Config;
use Mailika\Validation\Validator;
use Throwable;

final readonly class LdapContactDirectory implements ContactDirectoryInterface
{
    public function __construct(private Config $config)
    {
    }

    public function configured(): bool
    {
        $tlsMode = $this->tlsMode();

        return $this->config->bool('contacts.ldap.enabled')
            && function_exists('ldap_connect')
            && $this->host() !== ''
            && Validator::tcpPort($this->port())
            && $this->config->string('contacts.ldap.base_dn') !== ''
            && Validator::hostAllowed($this->host(), $this->config->stringList('contacts.ldap.allowed_hosts'))
            && (!$this->config->bool('contacts.ldap.require_tls', true)
                || in_array($tlsMode, ['starttls', 'tls'], true));
    }

    public function name(): string
    {
        return 'LDAP directory';
    }

    /**
     * @return list<Contact>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if (!$this->configured() || mb_strlen($query) < 2 || mb_strlen($query) > 128) {
            return [];
        }

        try {
            return $this->searchDirectory($query);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<Contact>
     */
    private function searchDirectory(string $query): array
    {
        $connection = $this->connect();
        if ($connection === null) {
            return [];
        }

        $filterValue = $this->escapeFilter($query);
        $filter = '(|(mail=*' . $filterValue . '*)(cn=*' . $filterValue . '*)(displayName=*'
            . $filterValue . '*))';
        $result = $this->ldapCall('ldap_search', [
            $connection,
            $this->config->string('contacts.ldap.base_dn'),
            $filter,
            ['cn', 'displayName', 'mail'],
            0,
            $this->maxResults(),
            $this->timeoutSeconds(),
        ]);

        if ($result === false) {
            return [];
        }

        $entries = $this->ldapCall('ldap_get_entries', [$connection, $result]);
        if (!is_array($entries)) {
            return [];
        }

        return $this->contactsFromEntries($entries);
    }

    private function connect(): mixed
    {
        $host = $this->host();
        $port = $this->port();
        $uri = ($this->tlsMode() === 'tls' ? 'ldaps' : 'ldap') . '://' . $host;
        $connection = $this->ldapCall('ldap_connect', [$uri, $port]);
        if ($connection === false || $connection === null) {
            return null;
        }

        $this->setOption($connection, 'LDAP_OPT_PROTOCOL_VERSION', 3);
        $this->setOption($connection, 'LDAP_OPT_REFERRALS', 0);
        $this->setOption($connection, 'LDAP_OPT_NETWORK_TIMEOUT', $this->timeoutSeconds());

        if ($this->tlsMode() === 'starttls') {
            $started = function_exists('ldap_start_tls')
                ? $this->ldapCall('ldap_start_tls', [$connection])
                : false;
            if ($started !== true) {
                return null;
            }
        }

        $bindDn = $this->config->string('contacts.ldap.bind_dn');
        $bound = $bindDn === ''
            ? $this->ldapCall('ldap_bind', [$connection])
            : $this->ldapCall('ldap_bind', [
                $connection,
                $bindDn,
                $this->config->string('contacts.ldap.bind_password'),
            ]);

        return $bound === true ? $connection : null;
    }

    /**
     * @param array<string|int, mixed> $entries
     * @return list<Contact>
     */
    private function contactsFromEntries(array $entries): array
    {
        $contacts = [];
        $count = (int) ($entries['count'] ?? 0);

        for ($index = 0; $index < $count; $index++) {
            $entry = $entries[$index] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            $email = Validator::normalizeEmail($this->firstAttribute($entry, 'mail'));
            if (!Validator::email($email)) {
                continue;
            }

            $displayName = $this->firstAttribute($entry, 'displayname')
                ?: $this->firstAttribute($entry, 'cn')
                ?: $email;
            $contacts[mb_strtolower($email)] = new Contact(
                mb_substr($displayName, 0, 255),
                $email,
                'Imported from LDAP directory',
            );
        }

        return array_values($contacts);
    }

    /**
     * @param array<string|int, mixed> $entry
     */
    private function firstAttribute(array $entry, string $name): string
    {
        $values = $entry[$name] ?? $entry[strtolower($name)] ?? $entry[ucfirst($name)] ?? null;
        if (is_array($values) && is_scalar($values[0] ?? null)) {
            return trim((string) $values[0]);
        }

        return is_scalar($values) ? trim((string) $values) : '';
    }

    private function setOption(mixed $connection, string $constant, int $value): void
    {
        if (!defined($constant) || !function_exists('ldap_set_option')) {
            return;
        }

        $this->ldapCall('ldap_set_option', [$connection, (int) constant($constant), $value]);
    }

    /**
     * @param list<mixed> $args
     */
    private function ldapCall(string $function, array $args): mixed
    {
        if (!function_exists($function)) {
            return false;
        }

        return @call_user_func_array($function, $args);
    }

    private function escapeFilter(string $value): string
    {
        if (function_exists('ldap_escape')) {
            $escaped = $this->ldapCall('ldap_escape', [
                $value,
                '',
                defined('LDAP_ESCAPE_FILTER') ? (int) constant('LDAP_ESCAPE_FILTER') : 1,
            ]);

            if (is_string($escaped)) {
                return $escaped;
            }
        }

        return strtr($value, [
            '\\' => '\5c',
            '*' => '\2a',
            '(' => '\28',
            ')' => '\29',
            "\0" => '\00',
        ]);
    }

    private function host(): string
    {
        return strtolower(trim($this->config->string('contacts.ldap.host')));
    }

    private function port(): int
    {
        return $this->config->int('contacts.ldap.port', 389);
    }

    private function tlsMode(): string
    {
        $mode = strtolower($this->config->string('contacts.ldap.tls', 'starttls'));
        return in_array($mode, ['none', 'starttls', 'tls'], true) ? $mode : 'starttls';
    }

    private function timeoutSeconds(): int
    {
        return max(1, min(30, $this->config->int('contacts.ldap.timeout_seconds', 5)));
    }

    private function maxResults(): int
    {
        return max(1, min(100, $this->config->int('contacts.ldap.max_results', 25)));
    }
}

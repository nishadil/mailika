<?php

declare(strict_types=1);

namespace Mailika\Validation;

use Symfony\Component\Mime\Address;
use Throwable;

final class Validator
{
    public static function email(string $value): bool
    {
        $value = self::normalizeEmail($value);
        if ($value === '' || strlen($value) > 320 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return false;
        }

        if (!str_contains($value, '@')) {
            return false;
        }

        [, $domain] = explode('@', $value, 2);
        if (self::domainToAscii($domain) === null) {
            return false;
        }

        try {
            new Address($value);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function headerText(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    public static function tcpPort(int $port): bool
    {
        return $port >= 1 && $port <= 65535;
    }

    public static function normalizeEmail(string $value): string
    {
        $value = trim($value);
        if (!str_contains($value, '@')) {
            return $value;
        }

        [$local, $domain] = explode('@', $value, 2);
        $domain = self::domainToAscii($domain) ?? strtolower(trim($domain));

        return $local . '@' . $domain;
    }

    public static function emailRequiresSmtpUtf8(string $value): bool
    {
        $value = self::normalizeEmail($value);
        if (!str_contains($value, '@')) {
            return false;
        }

        [$local] = explode('@', $value, 2);
        return preg_match('/[^\x00-\x7F]/', $local) === 1;
    }

    /**
     * @return list<string>
     */
    public static function emailList(string $value): array
    {
        $parts = preg_split('/[,;]/', $value) ?: [];
        $emails = [];

        foreach ($parts as $part) {
            $email = self::normalizeEmail($part);
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * @param list<string> $allowedHosts
     */
    public static function hostAllowed(string $host, array $allowedHosts): bool
    {
        $host = strtolower($host);

        if ($host === '' || strlen($host) > 253 || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            return false;
        }

        if (in_array('*', $allowedHosts, true)) {
            return true;
        }

        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = strtolower($allowedHost);
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private static function domainToAscii(string $domain): ?string
    {
        $domain = trim($domain);
        if ($domain === '' || strlen($domain) > 253 || preg_match('/[\x00-\x1F\x7F]/', $domain)) {
            return null;
        }

        if (function_exists('idn_to_ascii')) {
            $flags = 0;
            $flagConstants = [
                'IDNA_DEFAULT',
                'IDNA_USE_STD3_RULES',
                'IDNA_CHECK_BIDI',
                'IDNA_CHECK_CONTEXTJ',
                'IDNA_NONTRANSITIONAL_TO_ASCII',
            ];

            foreach ($flagConstants as $constant) {
                if (defined($constant)) {
                    $flags |= (int) constant($constant);
                }
            }

            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? (int) constant('INTL_IDNA_VARIANT_UTS46') : 1;
            $ascii = idn_to_ascii($domain, $flags, $variant);
            if (!is_string($ascii) || $ascii === '') {
                return null;
            }

            return strtolower($ascii);
        }

        if (preg_match('/[^\x00-\x7F]/', $domain) === 1) {
            return null;
        }

        return strtolower($domain);
    }
}

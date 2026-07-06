<?php

declare(strict_types=1);

namespace Mailika\Validation;

final class Validator
{
    public static function email(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false && strlen($value) <= 320;
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
}

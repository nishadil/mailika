<?php

declare(strict_types=1);

namespace Mailika\Auth;

use Mailika\Mail\MailAccountProfile;

final class LoginPrefillStore
{
    private const SESSION_KEY = 'mailika_login_prefill';

    public function store(MailAccountProfile $profile): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'email' => $profile->email,
            'imap_host' => $profile->imapHost,
            'imap_port' => $profile->imapPort,
            'imap_tls' => $profile->imapTls,
            'smtp_host' => $profile->smtpHost,
            'smtp_port' => $profile->smtpPort,
            'smtp_tls' => $profile->smtpTls,
        ];
    }

    /**
     * @return array{
     *     email?:string,
     *     imap_host?:string,
     *     imap_port?:int,
     *     imap_tls?:bool,
     *     smtp_host?:string,
     *     smtp_port?:int,
     *     smtp_tls?:string
     * }
     */
    public function pull(): array
    {
        $row = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        if (!is_array($row)) {
            return [];
        }

        return array_filter([
            'email' => $this->stringValue($row, 'email'),
            'imap_host' => $this->stringValue($row, 'imap_host'),
            'imap_port' => $this->intValue($row, 'imap_port'),
            'imap_tls' => $this->boolValue($row, 'imap_tls'),
            'smtp_host' => $this->stringValue($row, 'smtp_host'),
            'smtp_port' => $this->intValue($row, 'smtp_port'),
            'smtp_tls' => $this->stringValue($row, 'smtp_tls'),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringValue(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intValue(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function boolValue(array $row, string $key): ?bool
    {
        $value = $row[$key] ?? null;
        return is_bool($value) ? $value : null;
    }
}

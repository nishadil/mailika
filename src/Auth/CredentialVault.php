<?php

declare(strict_types=1);

namespace Mailika\Auth;

use Mailika\Config\Config;
use RuntimeException;

final readonly class CredentialVault
{
    private const SESSION_KEY = 'mailika_mailbox';

    private string $key;

    public function __construct(Config $config)
    {
        $this->key = self::parseKey($config->string('app.key'));
    }

    public function store(MailboxCredentials $credentials): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'email' => $credentials->email,
            'password' => $this->sealString($credentials->password),
            'imap_host' => $credentials->imapHost,
            'imap_port' => $credentials->imapPort,
            'imap_tls' => $credentials->imapTls,
            'smtp_host' => $credentials->smtpHost,
            'smtp_port' => $credentials->smtpPort,
            'smtp_tls' => $credentials->smtpTls,
        ];
    }

    public function current(): ?MailboxCredentials
    {
        $payload = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($payload)) {
            return null;
        }

        return new MailboxCredentials(
            (string) ($payload['email'] ?? ''),
            $this->openString((string) ($payload['password'] ?? '')),
            (string) ($payload['imap_host'] ?? ''),
            (int) ($payload['imap_port'] ?? 993),
            (bool) ($payload['imap_tls'] ?? true),
            (string) ($payload['smtp_host'] ?? ''),
            (int) ($payload['smtp_port'] ?? 587),
            (string) ($payload['smtp_tls'] ?? 'starttls'),
        );
    }

    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public function isAuthenticated(): bool
    {
        return $this->current() !== null;
    }

    private function sealString(string $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($value, $nonce, $this->key);
        return base64_encode($nonce . $ciphertext);
    }

    private function openString(string $sealed): string
    {
        $decoded = base64_decode($sealed, true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Invalid sealed credential payload.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new RuntimeException('Unable to open sealed credential payload.');
        }

        return $plaintext;
    }

    private static function parseKey(string $raw): string
    {
        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $decoded;
            }
        }

        if (strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $raw;
        }

        throw new RuntimeException('APP_KEY must be a base64 encoded 32-byte Sodium key.');
    }
}

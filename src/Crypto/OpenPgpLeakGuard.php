<?php

declare(strict_types=1);

namespace Mailika\Crypto;

final readonly class OpenPgpLeakGuard
{
    private const PRIVATE_KEY_ARMOR = '-----BEGIN PGP PRIVATE KEY BLOCK-----';
    private const SCAN_CHUNK_BYTES = 8192;

    /**
     * @param list<array{path:string,name:string,mime:string}> $attachments
     */
    public function assertNoPrivateKeyMaterial(string $body, array $attachments = []): void
    {
        if ($this->containsPrivateKeyArmor($body)) {
            throw new OpenPgpLeakException('OpenPGP private key material is not allowed.');
        }

        foreach ($attachments as $attachment) {
            if ($this->attachmentContainsPrivateKeyArmor($attachment['path'])) {
                throw new OpenPgpLeakException('OpenPGP private key material is not allowed.');
            }
        }
    }

    public function containsPrivateKeyArmor(string $content): bool
    {
        return stripos($content, self::PRIVATE_KEY_ARMOR) !== false;
    }

    private function attachmentContainsPrivateKeyArmor(string $path): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $tail = '';
        $overlapBytes = strlen(self::PRIVATE_KEY_ARMOR) - 1;

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, self::SCAN_CHUNK_BYTES);
                if ($chunk === false) {
                    return false;
                }

                if ($chunk === '') {
                    continue;
                }

                $window = $tail . $chunk;
                if ($this->containsPrivateKeyArmor($window)) {
                    return true;
                }

                $tail = substr($window, -$overlapBytes);
            }

            return false;
        } finally {
            fclose($handle);
        }
    }
}

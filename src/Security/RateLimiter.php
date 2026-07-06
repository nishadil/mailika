<?php

declare(strict_types=1);

namespace Mailika\Security;

final readonly class RateLimiter
{
    public function __construct(private string $cachePath)
    {
    }

    public function allow(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0775, true);
        }

        $file = $this->cachePath . '/rate-' . hash('sha256', $key) . '.json';
        $now = time();
        $attempts = [];

        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $attempts = array_values(array_filter(
                    array_map('intval', $decoded),
                    static fn (int $timestamp): bool => $timestamp > $now - $windowSeconds,
                ));
            }
        }

        if (count($attempts) >= $maxAttempts) {
            return false;
        }

        $attempts[] = $now;
        file_put_contents($file, json_encode($attempts, JSON_THROW_ON_ERROR), LOCK_EX);

        return true;
    }
}

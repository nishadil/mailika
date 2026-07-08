<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class PhpImapQuotaParser
{
    public function parse(mixed $quota): MailboxQuota
    {
        $storage = $this->storageQuota($quota, true);
        if ($storage === null) {
            return new MailboxQuota();
        }

        [$used, $limit] = $storage;

        return new MailboxQuota($used * 1024, $limit * 1024);
    }

    /**
     * @return array{0:int, 1:int}|null
     */
    private function storageQuota(mixed $quota, bool $allowDirectUsageLimit): ?array
    {
        if (!is_array($quota)) {
            return null;
        }

        foreach (['STORAGE', 'storage'] as $key) {
            if (isset($quota[$key]) && is_array($quota[$key])) {
                $parsed = $this->usageLimit($quota[$key]);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        if ($allowDirectUsageLimit) {
            $parsed = $this->usageLimit($quota);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        foreach ($quota as $value) {
            $parsed = $this->storageQuota($value, false);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $quota
     * @return array{0:int, 1:int}|null
     */
    private function usageLimit(array $quota): ?array
    {
        $used = $quota['usage'] ?? $quota['used'] ?? null;
        $limit = $quota['limit'] ?? null;

        if (!is_numeric($used) || !is_numeric($limit)) {
            return null;
        }

        return [(int) $used, (int) $limit];
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Cache;

interface RedisConnectorInterface
{
    public function available(): bool;

    public function ping(string $dsn): bool;

    public function incrementWithTtl(string $dsn, string $key, int $ttlSeconds): int;
}

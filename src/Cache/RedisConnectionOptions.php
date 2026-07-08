<?php

declare(strict_types=1);

namespace Mailika\Cache;

final readonly class RedisConnectionOptions
{
    public function __construct(
        public string $host,
        public int $port,
        public float $timeoutSeconds,
        public ?int $database,
        public ?string $username,
        public ?string $password,
    ) {
    }
}

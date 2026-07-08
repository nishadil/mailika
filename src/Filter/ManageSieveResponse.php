<?php

declare(strict_types=1);

namespace Mailika\Filter;

final readonly class ManageSieveResponse
{
    /**
     * @param array<string, string> $capabilities
     * @param list<string> $lines
     */
    public function __construct(
        public string $status,
        public string $message,
        public array $capabilities,
        public array $lines,
    ) {
    }

    public function ok(): bool
    {
        return strcasecmp($this->status, 'OK') === 0;
    }

    public function capability(string $name): ?string
    {
        $key = strtoupper($name);
        return $this->capabilities[$key] ?? null;
    }
}

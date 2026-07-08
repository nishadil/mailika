<?php

declare(strict_types=1);

namespace Mailika\Filter;

final readonly class SievePublishResult
{
    public function __construct(
        public bool $published,
        public string $message,
    ) {
    }
}

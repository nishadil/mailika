<?php

declare(strict_types=1);

namespace Mailika\Identity;

final readonly class Identity
{
    public function __construct(
        public string $displayName,
        public string $email,
        public ?string $replyTo = null,
        public bool $default = false,
        public ?int $id = null,
    ) {
    }
}

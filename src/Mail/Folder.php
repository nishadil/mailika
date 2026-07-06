<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class Folder
{
    public function __construct(
        public string $name,
        public string $displayName,
        public int $unread = 0,
    ) {
    }
}

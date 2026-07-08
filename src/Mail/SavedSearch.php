<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class SavedSearch
{
    public function __construct(
        public string $name,
        public string $folder,
        public string $query,
        public ?int $id = null,
    ) {
    }
}

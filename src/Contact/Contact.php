<?php

declare(strict_types=1);

namespace Mailika\Contact;

final readonly class Contact
{
    public function __construct(
        public string $displayName,
        public string $email,
        public ?string $notes = null,
        public ?int $id = null,
    ) {
    }
}

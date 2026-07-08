<?php

declare(strict_types=1);

namespace Mailika\Contact;

final readonly class ContactGroup
{
    public function __construct(
        public string $name,
        public ?int $id = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Contact;

interface ContactDirectoryInterface
{
    public function configured(): bool;

    public function name(): string;

    /**
     * @return list<Contact>
     */
    public function search(string $query): array;
}

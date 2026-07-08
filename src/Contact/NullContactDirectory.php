<?php

declare(strict_types=1);

namespace Mailika\Contact;

final readonly class NullContactDirectory implements ContactDirectoryInterface
{
    public function configured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'Directory';
    }

    /**
     * @return list<Contact>
     */
    public function search(string $query): array
    {
        return [];
    }
}

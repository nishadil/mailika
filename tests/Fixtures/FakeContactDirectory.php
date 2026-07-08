<?php

declare(strict_types=1);

namespace Mailika\Tests\Fixtures;

use Mailika\Contact\Contact;
use Mailika\Contact\ContactDirectoryInterface;

final readonly class FakeContactDirectory implements ContactDirectoryInterface
{
    /**
     * @param list<Contact> $contacts
     */
    public function __construct(
        private array $contacts,
        private bool $configured = true,
    ) {
    }

    public function configured(): bool
    {
        return $this->configured;
    }

    public function name(): string
    {
        return 'Fixture directory';
    }

    /**
     * @return list<Contact>
     */
    public function search(string $query): array
    {
        $query = mb_strtolower($query);

        return array_values(array_filter(
            $this->contacts,
            static fn (Contact $contact): bool => str_contains(mb_strtolower($contact->displayName), $query)
                || str_contains(mb_strtolower($contact->email), $query),
        ));
    }
}

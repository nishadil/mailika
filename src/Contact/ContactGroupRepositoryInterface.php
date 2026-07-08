<?php

declare(strict_types=1);

namespace Mailika\Contact;

interface ContactGroupRepositoryInterface
{
    /**
     * @return list<ContactGroup>
     */
    public function listForMailbox(string $mailboxIdentity): array;

    public function save(string $mailboxIdentity, ContactGroup $group): void;

    public function assignContactByEmail(string $mailboxIdentity, string $email, int $groupId): void;

    public function deleteForMailbox(string $mailboxIdentity, int $groupId): void;
}

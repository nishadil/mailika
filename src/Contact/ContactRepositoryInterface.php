<?php

declare(strict_types=1);

namespace Mailika\Contact;

interface ContactRepositoryInterface
{
    /**
     * @return list<Contact>
     */
    public function listForMailbox(string $mailboxIdentity): array;

    public function save(string $mailboxIdentity, Contact $contact): void;

    public function deleteForMailbox(string $mailboxIdentity, string $email): void;
}

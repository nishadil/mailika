<?php

declare(strict_types=1);

namespace Mailika\Mail;

interface SavedSearchRepositoryInterface
{
    /**
     * @return list<SavedSearch>
     */
    public function listForMailbox(string $mailboxIdentity): array;

    public function save(string $mailboxIdentity, SavedSearch $search): void;

    public function deleteForMailbox(string $mailboxIdentity, int $id): void;
}

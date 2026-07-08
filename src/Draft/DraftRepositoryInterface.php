<?php

declare(strict_types=1);

namespace Mailika\Draft;

interface DraftRepositoryInterface
{
    /**
     * @return list<DraftMessage>
     */
    public function listForMailbox(string $mailboxIdentity): array;

    public function findForMailbox(string $mailboxIdentity, string $id): ?DraftMessage;

    public function save(string $mailboxIdentity, DraftMessage $draft): void;

    public function deleteForMailbox(string $mailboxIdentity, string $id): void;
}

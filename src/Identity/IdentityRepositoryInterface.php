<?php

declare(strict_types=1);

namespace Mailika\Identity;

interface IdentityRepositoryInterface
{
    /**
     * @return list<Identity>
     */
    public function listForMailbox(string $mailboxIdentity): array;

    public function save(string $mailboxIdentity, Identity $identity): void;

    public function deleteForMailbox(string $mailboxIdentity, int $id): void;
}

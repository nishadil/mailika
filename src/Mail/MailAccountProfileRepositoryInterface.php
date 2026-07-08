<?php

declare(strict_types=1);

namespace Mailika\Mail;

interface MailAccountProfileRepositoryInterface
{
    /**
     * @return list<MailAccountProfile>
     */
    public function listForMailbox(string $mailboxIdentity): array;

    public function save(string $mailboxIdentity, MailAccountProfile $profile): void;

    public function deleteForMailbox(string $mailboxIdentity, int $id): void;
}

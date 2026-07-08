<?php

declare(strict_types=1);

namespace Mailika\Mail;

interface MessageMetadataCacheRepositoryInterface
{
    /**
     * @param list<MessageEnvelope> $messages
     */
    public function store(string $mailboxIdentity, string $folder, array $messages): void;

    /**
     * @return list<MessageEnvelope>
     */
    public function list(
        string $mailboxIdentity,
        string $folder,
        int $limit = 50,
        int $page = 1,
        string $query = '',
        ?MessageSearchCriteria $criteria = null,
    ): array;

    public function updateFlags(
        string $mailboxIdentity,
        string $folder,
        string $messageId,
        ?bool $seen = null,
        ?bool $flagged = null,
        ?bool $deleted = null,
    ): void;

    public function move(
        string $mailboxIdentity,
        string $folder,
        string $messageId,
        string $targetFolder,
    ): void;

    public function copy(
        string $mailboxIdentity,
        string $folder,
        string $messageId,
        string $targetFolder,
    ): void;

    public function delete(string $mailboxIdentity, string $folder, string $messageId): void;
}

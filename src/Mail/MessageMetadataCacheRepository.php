<?php

declare(strict_types=1);

namespace Mailika\Mail;

use PDO;

final readonly class MessageMetadataCacheRepository implements MessageMetadataCacheRepositoryInterface
{
    private const MAX_ROWS_PER_FOLDER = 10_000;

    public function __construct(private PDO $pdo)
    {
    }

    public function store(string $mailboxIdentity, string $folder, array $messages): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO message_metadata_cache
               (mailbox_identity, folder, message_uid, subject, sender, sent_at, flags,
                has_attachments, thread_id, cached_at)
             VALUES
               (:mailbox, :folder, :message_uid, :subject, :sender, :sent_at, :flags,
                :has_attachments, :thread_id, CURRENT_TIMESTAMP)
             ON CONFLICT (mailbox_identity, folder, message_uid) DO UPDATE SET
               subject = EXCLUDED.subject,
               sender = EXCLUDED.sender,
               sent_at = EXCLUDED.sent_at,
               flags = EXCLUDED.flags,
               has_attachments = EXCLUDED.has_attachments,
               thread_id = EXCLUDED.thread_id,
               cached_at = CURRENT_TIMESTAMP',
        );

        foreach ($messages as $message) {
            $statement->execute([
                'mailbox' => $mailboxIdentity,
                'folder' => $folder,
                'message_uid' => $message->id,
                'subject' => $message->subject,
                'sender' => $message->from,
                'sent_at' => $this->sentAt($message->date),
                'flags' => $this->flags($message),
                'has_attachments' => $message->hasAttachments ? 1 : 0,
                'thread_id' => $message->threadId,
            ]);
        }

        $this->prune($mailboxIdentity, $folder);
    }

    public function list(
        string $mailboxIdentity,
        string $folder,
        int $limit = 50,
        int $page = 1,
        string $query = '',
        ?MessageSearchCriteria $criteria = null,
    ): array {
        $criteria ??= new MessageSearchCriteria($folder, $query, $page, $limit);
        $parameters = [
            'mailbox' => $mailboxIdentity,
            'folder' => $criteria->folder,
        ];

        $statement = $this->pdo->prepare(
            'SELECT message_uid, subject, sender, sent_at, flags, has_attachments, thread_id
             FROM message_metadata_cache
             WHERE mailbox_identity = :mailbox AND folder = :folder
             ORDER BY cached_at DESC',
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->execute();

        $messages = [];
        foreach ($statement->fetchAll() as $row) {
            $message = $this->hydrate($row);
            if ($this->messageMatches($message, $criteria)) {
                $messages[] = $message;
            }
        }

        return array_slice(
            $messages,
            max(0, $criteria->page - 1) * $criteria->limit,
            $criteria->limit,
        );
    }

    public function updateFlags(
        string $mailboxIdentity,
        string $folder,
        string $messageId,
        ?bool $seen = null,
        ?bool $flagged = null,
        ?bool $deleted = null,
    ): void {
        $row = $this->cachedRow($mailboxIdentity, $folder, $messageId);
        if ($row === null) {
            return;
        }

        $flags = $this->decodedFlags($row);
        if ($seen !== null) {
            $flags['seen'] = $seen;
        }

        if ($flagged !== null) {
            $flags['flagged'] = $flagged;
        }

        if ($deleted !== null) {
            $flags['deleted'] = $deleted;
        }

        $statement = $this->pdo->prepare(
            'UPDATE message_metadata_cache
             SET flags = :flags, cached_at = CURRENT_TIMESTAMP
             WHERE mailbox_identity = :mailbox AND folder = :folder AND message_uid = :message_uid',
        );
        $statement->execute([
            'flags' => json_encode($flags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'mailbox' => $mailboxIdentity,
            'folder' => $folder,
            'message_uid' => $messageId,
        ]);
    }

    public function move(
        string $mailboxIdentity,
        string $folder,
        string $messageId,
        string $targetFolder,
    ): void {
        if ($targetFolder === '' || $targetFolder === $folder) {
            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE message_metadata_cache
             SET folder = :target_folder, cached_at = CURRENT_TIMESTAMP
             WHERE mailbox_identity = :mailbox AND folder = :folder AND message_uid = :message_uid',
        );
        $statement->execute([
            'target_folder' => $targetFolder,
            'mailbox' => $mailboxIdentity,
            'folder' => $folder,
            'message_uid' => $messageId,
        ]);
        $this->prune($mailboxIdentity, $targetFolder);
    }

    public function copy(
        string $mailboxIdentity,
        string $folder,
        string $messageId,
        string $targetFolder,
    ): void {
        if ($targetFolder === '' || $targetFolder === $folder) {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO message_metadata_cache
                (mailbox_identity, folder, message_uid, subject, sender, sent_at, flags,
                 has_attachments, thread_id, cached_at)
             SELECT mailbox_identity, :target_folder, message_uid, subject, sender, sent_at, flags,
                    has_attachments, thread_id, CURRENT_TIMESTAMP
             FROM message_metadata_cache
             WHERE mailbox_identity = :mailbox AND folder = :folder AND message_uid = :message_uid
             ON CONFLICT (mailbox_identity, folder, message_uid) DO UPDATE SET
                subject = EXCLUDED.subject,
                sender = EXCLUDED.sender,
                sent_at = EXCLUDED.sent_at,
                flags = EXCLUDED.flags,
                has_attachments = EXCLUDED.has_attachments,
                thread_id = EXCLUDED.thread_id,
                cached_at = CURRENT_TIMESTAMP',
        );
        $statement->execute([
            'target_folder' => $targetFolder,
            'mailbox' => $mailboxIdentity,
            'folder' => $folder,
            'message_uid' => $messageId,
        ]);
        $this->prune($mailboxIdentity, $targetFolder);
    }

    public function delete(string $mailboxIdentity, string $folder, string $messageId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM message_metadata_cache
             WHERE mailbox_identity = :mailbox AND folder = :folder AND message_uid = :message_uid',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'folder' => $folder,
            'message_uid' => $messageId,
        ]);
    }

    private function sentAt(string $date): ?string
    {
        $timestamp = strtotime($date);
        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function flags(MessageEnvelope $message): string
    {
        return json_encode([
            'date' => $message->date,
            'seen' => $message->seen,
            'flagged' => $message->flagged,
            'answered' => $message->answered,
            'deleted' => $message->deleted,
            'draft' => $message->draft,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MessageEnvelope
    {
        $flags = $this->decodedFlags($row);

        return new MessageEnvelope(
            (string) $row['message_uid'],
            (string) ($row['sender'] ?? ''),
            (string) ($row['subject'] ?? '(no subject)'),
            (string) ($flags['date'] ?? $row['sent_at'] ?? ''),
            $this->boolValue($flags['seen'] ?? false),
            $this->boolValue($row['has_attachments'] ?? false),
            $this->boolValue($flags['flagged'] ?? false),
            $this->boolValue($flags['answered'] ?? false),
            $this->boolValue($flags['deleted'] ?? false),
            $this->boolValue($flags['draft'] ?? false),
            $row['thread_id'] === null ? null : (string) $row['thread_id'],
        );
    }

    private function boolValue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private function messageMatches(MessageEnvelope $message, MessageSearchCriteria $criteria): bool
    {
        if ($criteria->unseenOnly && $message->seen) {
            return false;
        }

        if ($criteria->flaggedOnly && !$message->flagged) {
            return false;
        }

        if ($criteria->query !== '' && !$this->messageContains($message, $criteria->query, ['subject', 'from'])) {
            return false;
        }

        if (
            $criteria->from !== null
            && $criteria->from !== ''
            && !$this->messageContains($message, $criteria->from, ['from'])
        ) {
            return false;
        }

        return $criteria->subject === null
            || $criteria->subject === ''
            || $this->messageContains($message, $criteria->subject, ['subject']);
    }

    /**
     * @param list<'subject'|'from'> $fields
     */
    private function messageContains(MessageEnvelope $message, string $needle, array $fields): bool
    {
        $needle = mb_strtolower($needle);
        foreach ($fields as $field) {
            $value = $field === 'from' ? $message->from : $message->subject;
            if (str_contains(mb_strtolower($value), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cachedRow(string $mailboxIdentity, string $folder, string $messageId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT message_uid, subject, sender, sent_at, flags, has_attachments, thread_id
             FROM message_metadata_cache
             WHERE mailbox_identity = :mailbox AND folder = :folder AND message_uid = :message_uid',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'folder' => $folder,
            'message_uid' => $messageId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodedFlags(array $row): array
    {
        $flags = json_decode((string) ($row['flags'] ?? '{}'), true);
        return is_array($flags) ? $flags : [];
    }

    private function prune(string $mailboxIdentity, string $folder): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM message_metadata_cache
             WHERE mailbox_identity = :mailbox
               AND folder = :folder
               AND message_uid IN (
                   SELECT message_uid
                   FROM message_metadata_cache
                   WHERE mailbox_identity = :mailbox_inner AND folder = :folder_inner
                   ORDER BY cached_at DESC
                   OFFSET :keep_count
               )',
        );
        $statement->bindValue('mailbox', $mailboxIdentity);
        $statement->bindValue('folder', $folder);
        $statement->bindValue('mailbox_inner', $mailboxIdentity);
        $statement->bindValue('folder_inner', $folder);
        $statement->bindValue('keep_count', self::MAX_ROWS_PER_FOLDER, PDO::PARAM_INT);
        $statement->execute();
    }
}

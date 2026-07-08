<?php

declare(strict_types=1);

namespace Mailika\Mail;

final class SessionMessageMetadataCacheRepository implements MessageMetadataCacheRepositoryInterface
{
    private const MAX_ROWS_PER_FOLDER = 10_000;

    public function store(string $mailboxIdentity, string $folder, array $messages): void
    {
        foreach ($messages as $message) {
            $sequence = (int) ($_SESSION['message_metadata_cache_sequence'] ?? 0) + 1;
            $_SESSION['message_metadata_cache_sequence'] = $sequence;
            $_SESSION['message_metadata_cache'][$mailboxIdentity][$folder][$message->id] = [
                'id' => $message->id,
                'from' => $message->from,
                'subject' => $message->subject,
                'date' => $message->date,
                'seen' => $message->seen,
                'has_attachments' => $message->hasAttachments,
                'flagged' => $message->flagged,
                'answered' => $message->answered,
                'deleted' => $message->deleted,
                'draft' => $message->draft,
                'thread_id' => $message->threadId,
                'cached_at' => $sequence,
            ];
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
        $rows = $this->rows($mailboxIdentity, $criteria->folder);
        usort(
            $rows,
            static fn (array $a, array $b): int => ((int) ($b['cached_at'] ?? 0)) <=> ((int) ($a['cached_at'] ?? 0)),
        );

        $rows = array_values(array_filter($rows, fn (array $row): bool => $this->rowMatches($row, $criteria)));
        $rows = array_slice($rows, max(0, $criteria->page - 1) * $criteria->limit, $criteria->limit);
        $messages = [];

        foreach ($rows as $row) {
            $messages[] = new MessageEnvelope(
                (string) ($row['id'] ?? ''),
                (string) ($row['from'] ?? ''),
                (string) ($row['subject'] ?? '(no subject)'),
                (string) ($row['date'] ?? ''),
                (bool) ($row['seen'] ?? false),
                (bool) ($row['has_attachments'] ?? false),
                (bool) ($row['flagged'] ?? false),
                (bool) ($row['answered'] ?? false),
                (bool) ($row['deleted'] ?? false),
                (bool) ($row['draft'] ?? false),
                $row['thread_id'] === null ? null : (string) $row['thread_id'],
            );
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowMatches(array $row, MessageSearchCriteria $criteria): bool
    {
        if ($criteria->unseenOnly && (bool) ($row['seen'] ?? false)) {
            return false;
        }

        if ($criteria->flaggedOnly && !(bool) ($row['flagged'] ?? false)) {
            return false;
        }

        if ($criteria->query !== '' && !$this->rowContains($row, $criteria->query, ['subject', 'from'])) {
            return false;
        }

        if (
            $criteria->from !== null
            && $criteria->from !== ''
            && !$this->rowContains($row, $criteria->from, ['from'])
        ) {
            return false;
        }

        return $criteria->subject === null
            || $criteria->subject === ''
            || $this->rowContains($row, $criteria->subject, ['subject']);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     */
    private function rowContains(array $row, string $needle, array $fields): bool
    {
        $needle = mb_strtolower($needle);
        foreach ($fields as $field) {
            if (str_contains(mb_strtolower((string) ($row[$field] ?? '')), $needle)) {
                return true;
            }
        }

        return false;
    }

    public function updateFlags(
        string $mailboxIdentity,
        string $folder,
        string $messageId,
        ?bool $seen = null,
        ?bool $flagged = null,
        ?bool $deleted = null,
    ): void {
        $rows = $this->rows($mailboxIdentity, $folder);
        foreach ($rows as $row) {
            if ((string) ($row['id'] ?? '') !== $messageId) {
                continue;
            }

            if ($seen !== null) {
                $row['seen'] = $seen;
            }

            if ($flagged !== null) {
                $row['flagged'] = $flagged;
            }

            if ($deleted !== null) {
                $row['deleted'] = $deleted;
            }

            $this->putRow($mailboxIdentity, $folder, $messageId, $row);
            return;
        }
    }

    public function move(
        string $mailboxIdentity,
        string $folder,
        string $messageId,
        string $targetFolder,
    ): void {
        $row = $this->rowById($mailboxIdentity, $folder, $messageId);
        if ($row === null || $targetFolder === '' || $targetFolder === $folder) {
            return;
        }

        $this->delete($mailboxIdentity, $folder, $messageId);
        $this->putRow($mailboxIdentity, $targetFolder, $messageId, $row);
        $this->prune($mailboxIdentity, $targetFolder);
    }

    public function copy(
        string $mailboxIdentity,
        string $folder,
        string $messageId,
        string $targetFolder,
    ): void {
        $row = $this->rowById($mailboxIdentity, $folder, $messageId);
        if ($row === null || $targetFolder === '' || $targetFolder === $folder) {
            return;
        }

        $this->putRow($mailboxIdentity, $targetFolder, $messageId, $row);
        $this->prune($mailboxIdentity, $targetFolder);
    }

    public function delete(string $mailboxIdentity, string $folder, string $messageId): void
    {
        $rows = $_SESSION['message_metadata_cache'][$mailboxIdentity][$folder] ?? [];
        if (!is_array($rows) || !array_key_exists($messageId, $rows)) {
            return;
        }

        unset($_SESSION['message_metadata_cache'][$mailboxIdentity][$folder][$messageId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $mailboxIdentity, string $folder): array
    {
        $rows = $_SESSION['message_metadata_cache'][$mailboxIdentity][$folder] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowById(string $mailboxIdentity, string $folder, string $messageId): ?array
    {
        $row = $_SESSION['message_metadata_cache'][$mailboxIdentity][$folder][$messageId] ?? null;
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function putRow(string $mailboxIdentity, string $folder, string $messageId, array $row): void
    {
        $sequence = (int) ($_SESSION['message_metadata_cache_sequence'] ?? 0) + 1;
        $_SESSION['message_metadata_cache_sequence'] = $sequence;
        $row['cached_at'] = $sequence;
        $_SESSION['message_metadata_cache'][$mailboxIdentity][$folder][$messageId] = $row;
    }

    private function prune(string $mailboxIdentity, string $folder): void
    {
        $rows = $_SESSION['message_metadata_cache'][$mailboxIdentity][$folder] ?? [];
        if (!is_array($rows) || count($rows) <= self::MAX_ROWS_PER_FOLDER) {
            return;
        }

        uasort(
            $rows,
            static fn (array $a, array $b): int => ((int) ($b['cached_at'] ?? 0)) <=> ((int) ($a['cached_at'] ?? 0)),
        );

        $_SESSION['message_metadata_cache'][$mailboxIdentity][$folder] = array_slice(
            $rows,
            0,
            self::MAX_ROWS_PER_FOLDER,
            true,
        );
    }
}

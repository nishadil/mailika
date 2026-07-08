<?php

declare(strict_types=1);

namespace Mailika\Mail;

final class SessionSavedSearchRepository implements SavedSearchRepositoryInterface
{
    /**
     * @return list<SavedSearch>
     */
    public function listForMailbox(string $mailboxIdentity): array
    {
        $searches = [];
        foreach ($this->rows($mailboxIdentity) as $index => $row) {
            $searches[] = new SavedSearch(
                (string) ($row['name'] ?? ''),
                (string) ($row['folder'] ?? 'INBOX'),
                (string) ($row['query'] ?? ''),
                $index + 1,
            );
        }

        usort($searches, static fn (SavedSearch $a, SavedSearch $b): int => $a->name <=> $b->name);

        return $searches;
    }

    public function save(string $mailboxIdentity, SavedSearch $search): void
    {
        $rows = $this->rows($mailboxIdentity);
        $normalizedName = mb_strtolower($search->name);
        foreach ($rows as $index => $row) {
            if (mb_strtolower((string) ($row['name'] ?? '')) !== $normalizedName) {
                continue;
            }

            $rows[$index] = [
                'name' => $search->name,
                'folder' => $search->folder,
                'query' => $search->query,
            ];
            $_SESSION['saved_searches_by_mailbox'][$mailboxIdentity] = $rows;
            return;
        }

        $_SESSION['saved_searches_by_mailbox'][$mailboxIdentity][] = [
            'name' => $search->name,
            'folder' => $search->folder,
            'query' => $search->query,
        ];
    }

    public function deleteForMailbox(string $mailboxIdentity, int $id): void
    {
        $allRows = $_SESSION['saved_searches_by_mailbox'] ?? [];
        if (!is_array($allRows)) {
            return;
        }

        $rows = $allRows[$mailboxIdentity] ?? [];
        if (!is_array($rows)) {
            return;
        }

        $index = $id - 1;
        if (!array_key_exists($index, $rows)) {
            return;
        }

        unset($rows[$index]);
        $_SESSION['saved_searches_by_mailbox'][$mailboxIdentity] = array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $mailboxIdentity): array
    {
        $allRows = $_SESSION['saved_searches_by_mailbox'] ?? [];
        if (!is_array($allRows)) {
            return [];
        }

        $rows = $allRows[$mailboxIdentity] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }
}

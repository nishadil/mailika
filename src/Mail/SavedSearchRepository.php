<?php

declare(strict_types=1);

namespace Mailika\Mail;

use PDO;

final readonly class SavedSearchRepository implements SavedSearchRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<SavedSearch>
     */
    public function listForMailbox(string $mailboxIdentity): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, folder, query
             FROM saved_searches
             WHERE mailbox_identity = :mailbox
             ORDER BY name',
        );
        $statement->execute(['mailbox' => $mailboxIdentity]);

        $searches = [];
        foreach ($statement->fetchAll() as $row) {
            $searches[] = new SavedSearch(
                (string) $row['name'],
                (string) $row['folder'],
                (string) $row['query'],
                (int) $row['id'],
            );
        }

        return $searches;
    }

    public function save(string $mailboxIdentity, SavedSearch $search): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO saved_searches (mailbox_identity, name, folder, query)
             VALUES (:mailbox, :name, :folder, :query)
             ON CONFLICT (mailbox_identity, (lower(name))) DO UPDATE SET
                name = EXCLUDED.name,
                folder = EXCLUDED.folder,
                query = EXCLUDED.query,
                updated_at = CURRENT_TIMESTAMP',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'name' => $search->name,
            'folder' => $search->folder,
            'query' => $search->query,
        ]);
    }

    public function deleteForMailbox(string $mailboxIdentity, int $id): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM saved_searches
             WHERE mailbox_identity = :mailbox AND id = :id',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'id' => $id,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Identity;

use PDO;

final readonly class IdentityRepository implements IdentityRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<Identity>
     */
    public function listForMailbox(string $mailboxIdentity): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, display_name, email, reply_to, is_default
             FROM identities
             WHERE mailbox_identity = :mailbox
             ORDER BY is_default DESC, display_name, email',
        );
        $statement->execute(['mailbox' => $mailboxIdentity]);

        $identities = [];
        foreach ($statement->fetchAll() as $row) {
            $identities[] = new Identity(
                (string) $row['display_name'],
                (string) $row['email'],
                $row['reply_to'] === null ? null : (string) $row['reply_to'],
                $this->boolValue($row['is_default'] ?? false),
                (int) $row['id'],
            );
        }

        return $identities;
    }

    public function save(string $mailboxIdentity, Identity $identity): void
    {
        if ($identity->default) {
            $statement = $this->pdo->prepare(
                'UPDATE identities
                 SET is_default = FALSE, updated_at = CURRENT_TIMESTAMP
                 WHERE mailbox_identity = :mailbox',
            );
            $statement->execute(['mailbox' => $mailboxIdentity]);
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO identities (mailbox_identity, display_name, email, reply_to, is_default)
             VALUES (:mailbox, :display_name, :email, :reply_to, :is_default)
             ON CONFLICT (mailbox_identity, (lower(email))) DO UPDATE SET
                display_name = EXCLUDED.display_name,
                email = EXCLUDED.email,
                reply_to = EXCLUDED.reply_to,
                is_default = EXCLUDED.is_default,
                updated_at = CURRENT_TIMESTAMP',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'display_name' => $identity->displayName,
            'email' => $identity->email,
            'reply_to' => $identity->replyTo,
            'is_default' => $identity->default ? 1 : 0,
        ]);

        $this->ensureDefault($mailboxIdentity);
    }

    public function deleteForMailbox(string $mailboxIdentity, int $id): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM identities
             WHERE mailbox_identity = :mailbox AND id = :id',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'id' => $id,
        ]);

        $this->ensureDefault($mailboxIdentity);
    }

    private function ensureDefault(string $mailboxIdentity): void
    {
        $default = $this->pdo->prepare(
            'SELECT 1
             FROM identities
             WHERE mailbox_identity = :mailbox AND is_default = TRUE
             LIMIT 1',
        );
        $default->execute(['mailbox' => $mailboxIdentity]);
        if ($default->fetchColumn() !== false) {
            return;
        }

        $first = $this->pdo->prepare(
            'SELECT id
             FROM identities
             WHERE mailbox_identity = :mailbox
             ORDER BY display_name, email, id
             LIMIT 1',
        );
        $first->execute(['mailbox' => $mailboxIdentity]);
        $id = $first->fetchColumn();
        if (!is_numeric($id)) {
            return;
        }

        $update = $this->pdo->prepare(
            'UPDATE identities
             SET is_default = TRUE, updated_at = CURRENT_TIMESTAMP
             WHERE mailbox_identity = :mailbox AND id = :id',
        );
        $update->execute([
            'mailbox' => $mailboxIdentity,
            'id' => (int) $id,
        ]);
    }

    private function boolValue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}

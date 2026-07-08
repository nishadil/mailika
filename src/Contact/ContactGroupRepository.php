<?php

declare(strict_types=1);

namespace Mailika\Contact;

use PDO;
use PDOException;

final readonly class ContactGroupRepository implements ContactGroupRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<ContactGroup>
     */
    public function listForMailbox(string $mailboxIdentity): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name
             FROM contact_groups
             WHERE mailbox_identity = :mailbox
             ORDER BY name',
        );
        $statement->execute(['mailbox' => $mailboxIdentity]);

        $groups = [];
        foreach ($statement->fetchAll() as $row) {
            $groups[] = new ContactGroup((string) $row['name'], (int) $row['id']);
        }

        return $groups;
    }

    public function save(string $mailboxIdentity, ContactGroup $group): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO contact_groups (mailbox_identity, name)
             VALUES (:mailbox, :name)
             ON CONFLICT (mailbox_identity, (lower(name))) DO UPDATE SET
                name = EXCLUDED.name,
                updated_at = CURRENT_TIMESTAMP',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'name' => $group->name,
        ]);
    }

    public function assignContactByEmail(string $mailboxIdentity, string $email, int $groupId): void
    {
        if (!$this->groupExists($mailboxIdentity, $groupId)) {
            return;
        }

        $statement = $this->pdo->prepare(
            'SELECT id
             FROM contacts
             WHERE mailbox_identity = :mailbox AND lower(email) = lower(:email)
             ORDER BY id DESC
             LIMIT 1',
        );
        $statement->execute(['mailbox' => $mailboxIdentity, 'email' => $email]);
        $contactId = $statement->fetchColumn();

        if (!is_numeric($contactId)) {
            return;
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO contact_group_memberships (contact_id, group_id)
                 VALUES (:contact_id, :group_id)',
            );
            $statement->execute(['contact_id' => (int) $contactId, 'group_id' => $groupId]);
        } catch (PDOException) {
            // Ignore duplicate membership attempts.
        }
    }

    public function deleteForMailbox(string $mailboxIdentity, int $groupId): void
    {
        if (!$this->groupExists($mailboxIdentity, $groupId)) {
            return;
        }

        $memberships = $this->pdo->prepare(
            'DELETE FROM contact_group_memberships WHERE group_id = :group_id',
        );
        $memberships->execute(['group_id' => $groupId]);

        $group = $this->pdo->prepare(
            'DELETE FROM contact_groups WHERE mailbox_identity = :mailbox AND id = :group_id',
        );
        $group->execute(['mailbox' => $mailboxIdentity, 'group_id' => $groupId]);
    }

    private function groupExists(string $mailboxIdentity, int $groupId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM contact_groups WHERE mailbox_identity = :mailbox AND id = :group_id',
        );
        $statement->execute(['mailbox' => $mailboxIdentity, 'group_id' => $groupId]);

        return $statement->fetchColumn() !== false;
    }
}

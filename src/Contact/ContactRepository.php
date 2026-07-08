<?php

declare(strict_types=1);

namespace Mailika\Contact;

use PDO;

final readonly class ContactRepository implements ContactRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<Contact>
     */
    public function listForMailbox(string $mailboxIdentity): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, display_name, email, notes
             FROM contacts
             WHERE mailbox_identity = :mailbox
             ORDER BY display_name',
        );
        $statement->execute(['mailbox' => $mailboxIdentity]);
        $groups = $this->groupsByContact($mailboxIdentity);

        $contacts = [];
        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['id'];
            $contacts[] = new Contact(
                (string) $row['display_name'],
                (string) $row['email'],
                $row['notes'] === null ? null : (string) $row['notes'],
                $id,
                $groups[$id] ?? [],
            );
        }

        return $contacts;
    }

    public function save(string $mailboxIdentity, Contact $contact): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO contacts (mailbox_identity, display_name, email, notes)
             VALUES (:mailbox, :display_name, :email, :notes)
             ON CONFLICT (mailbox_identity, (lower(email))) DO UPDATE SET
                display_name = EXCLUDED.display_name,
                email = EXCLUDED.email,
                notes = EXCLUDED.notes,
                updated_at = CURRENT_TIMESTAMP',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'display_name' => $contact->displayName,
            'email' => $contact->email,
            'notes' => $contact->notes,
        ]);
    }

    public function deleteForMailbox(string $mailboxIdentity, string $email): void
    {
        $select = $this->pdo->prepare(
            'SELECT id
             FROM contacts
             WHERE mailbox_identity = :mailbox AND email = :email',
        );
        $select->execute([
            'mailbox' => $mailboxIdentity,
            'email' => $email,
        ]);

        $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
        if ($ids !== []) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $memberships = $this->pdo->prepare(
                sprintf('DELETE FROM contact_group_memberships WHERE contact_id IN (%s)', $placeholders),
            );
            $memberships->execute($ids);
        }

        $delete = $this->pdo->prepare(
            'DELETE FROM contacts
             WHERE mailbox_identity = :mailbox AND email = :email',
        );
        $delete->execute([
            'mailbox' => $mailboxIdentity,
            'email' => $email,
        ]);
    }

    /**
     * @return array<int, list<string>>
     */
    private function groupsByContact(string $mailboxIdentity): array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.contact_id, g.name
             FROM contact_group_memberships m
             INNER JOIN contact_groups g ON g.id = m.group_id
             WHERE g.mailbox_identity = :mailbox
             ORDER BY g.name',
        );
        $statement->execute(['mailbox' => $mailboxIdentity]);

        $groups = [];
        foreach ($statement->fetchAll() as $row) {
            $contactId = (int) $row['contact_id'];
            $groups[$contactId][] = (string) $row['name'];
        }

        return $groups;
    }
}

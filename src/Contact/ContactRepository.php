<?php

declare(strict_types=1);

namespace Mailika\Contact;

use PDO;

final readonly class ContactRepository
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

        $contacts = [];
        foreach ($statement->fetchAll() as $row) {
            $contacts[] = new Contact(
                (string) $row['display_name'],
                (string) $row['email'],
                $row['notes'] === null ? null : (string) $row['notes'],
                (int) $row['id'],
            );
        }

        return $contacts;
    }

    public function save(string $mailboxIdentity, Contact $contact): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO contacts (mailbox_identity, display_name, email, notes)
             VALUES (:mailbox, :display_name, :email, :notes)',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'display_name' => $contact->displayName,
            'email' => $contact->email,
            'notes' => $contact->notes,
        ]);
    }
}

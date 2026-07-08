<?php

declare(strict_types=1);

namespace Mailika\Draft;

use PDO;

final readonly class DraftRepository implements DraftRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<DraftMessage>
     */
    public function listForMailbox(string $mailboxIdentity): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, recipients_to, recipients_cc, recipients_bcc, subject, body_text, updated_at, identity_email
             FROM drafts
             WHERE mailbox_identity = :mailbox
             ORDER BY updated_at DESC, id DESC',
        );
        $statement->execute(['mailbox' => $mailboxIdentity]);

        $drafts = [];
        foreach ($statement->fetchAll() as $row) {
            $drafts[] = $this->hydrate($row);
        }

        return $drafts;
    }

    public function findForMailbox(string $mailboxIdentity, string $id): ?DraftMessage
    {
        $statement = $this->pdo->prepare(
            'SELECT id, recipients_to, recipients_cc, recipients_bcc, subject, body_text, updated_at, identity_email
             FROM drafts
             WHERE mailbox_identity = :mailbox AND id = :id',
        );
        $statement->execute(['mailbox' => $mailboxIdentity, 'id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function save(string $mailboxIdentity, DraftMessage $draft): void
    {
        if ($draft->id !== '' && $this->findForMailbox($mailboxIdentity, $draft->id) !== null) {
            $statement = $this->pdo->prepare(
                'UPDATE drafts
                 SET recipients_to = :recipients_to,
                     recipients_cc = :recipients_cc,
                     recipients_bcc = :recipients_bcc,
                     subject = :subject,
                     body_text = :body_text,
                     identity_email = :identity_email,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE mailbox_identity = :mailbox AND id = :id',
            );
            $statement->execute([
                'mailbox' => $mailboxIdentity,
                'id' => $draft->id,
                'recipients_to' => $draft->to,
                'recipients_cc' => $draft->cc === '' ? null : $draft->cc,
                'recipients_bcc' => $draft->bcc === '' ? null : $draft->bcc,
                'subject' => $draft->subject,
                'body_text' => $draft->body,
                'identity_email' => $draft->identityEmail,
            ]);

            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO drafts
               (mailbox_identity, recipients_to, recipients_cc, recipients_bcc,
                subject, body_text, identity_email, updated_at)
             VALUES
               (:mailbox, :recipients_to, :recipients_cc, :recipients_bcc,
                :subject, :body_text, :identity_email, CURRENT_TIMESTAMP)',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'recipients_to' => $draft->to,
            'recipients_cc' => $draft->cc === '' ? null : $draft->cc,
            'recipients_bcc' => $draft->bcc === '' ? null : $draft->bcc,
            'subject' => $draft->subject,
            'body_text' => $draft->body,
            'identity_email' => $draft->identityEmail,
        ]);
    }

    public function deleteForMailbox(string $mailboxIdentity, string $id): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM drafts WHERE mailbox_identity = :mailbox AND id = :id',
        );
        $statement->execute(['mailbox' => $mailboxIdentity, 'id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DraftMessage
    {
        return new DraftMessage(
            (string) $row['id'],
            (string) $row['recipients_to'],
            $row['recipients_cc'] === null ? '' : (string) $row['recipients_cc'],
            $row['recipients_bcc'] === null ? '' : (string) $row['recipients_bcc'],
            $row['subject'] === null ? '' : (string) $row['subject'],
            (string) $row['body_text'],
            (string) $row['updated_at'],
            isset($row['identity_email']) && $row['identity_email'] !== '' ? (string) $row['identity_email'] : null,
        );
    }
}

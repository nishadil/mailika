<?php

declare(strict_types=1);

namespace Mailika\Mail;

use PDO;

final readonly class MailAccountProfileRepository implements MailAccountProfileRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<MailAccountProfile>
     */
    public function listForMailbox(string $mailboxIdentity): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, label, email, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls
             FROM mail_account_profiles
             WHERE mailbox_identity = :mailbox
             ORDER BY label',
        );
        $statement->execute(['mailbox' => $mailboxIdentity]);

        $profiles = [];
        foreach ($statement->fetchAll() as $row) {
            $profiles[] = new MailAccountProfile(
                (string) $row['label'],
                (string) $row['email'],
                (string) $row['imap_host'],
                (int) $row['imap_port'],
                $this->boolValue($row['imap_tls'] ?? true),
                (string) $row['smtp_host'],
                (int) $row['smtp_port'],
                (string) $row['smtp_tls'],
                (int) $row['id'],
            );
        }

        return $profiles;
    }

    public function save(string $mailboxIdentity, MailAccountProfile $profile): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO mail_account_profiles
                (mailbox_identity, label, email, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls)
             VALUES
                (:mailbox, :label, :email, :imap_host, :imap_port, :imap_tls, :smtp_host, :smtp_port, :smtp_tls)
             ON CONFLICT (mailbox_identity, (lower(email))) DO UPDATE SET
                label = EXCLUDED.label,
                email = EXCLUDED.email,
                imap_host = EXCLUDED.imap_host,
                imap_port = EXCLUDED.imap_port,
                imap_tls = EXCLUDED.imap_tls,
                smtp_host = EXCLUDED.smtp_host,
                smtp_port = EXCLUDED.smtp_port,
                smtp_tls = EXCLUDED.smtp_tls,
                updated_at = CURRENT_TIMESTAMP',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'label' => $profile->label,
            'email' => $profile->email,
            'imap_host' => $profile->imapHost,
            'imap_port' => $profile->imapPort,
            'imap_tls' => $profile->imapTls ? 1 : 0,
            'smtp_host' => $profile->smtpHost,
            'smtp_port' => $profile->smtpPort,
            'smtp_tls' => $profile->smtpTls,
        ]);
    }

    public function deleteForMailbox(string $mailboxIdentity, int $id): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM mail_account_profiles
             WHERE mailbox_identity = :mailbox AND id = :id',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'id' => $id,
        ]);
    }

    private function boolValue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}

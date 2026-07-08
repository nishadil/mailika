<?php

declare(strict_types=1);

namespace Mailika\Mail;

final class SessionMailAccountProfileRepository implements MailAccountProfileRepositoryInterface
{
    /**
     * @return list<MailAccountProfile>
     */
    public function listForMailbox(string $mailboxIdentity): array
    {
        $profiles = [];
        foreach ($this->rows($mailboxIdentity) as $index => $row) {
            $profiles[] = new MailAccountProfile(
                (string) ($row['label'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['imap_host'] ?? ''),
                (int) ($row['imap_port'] ?? 993),
                (bool) ($row['imap_tls'] ?? true),
                (string) ($row['smtp_host'] ?? ''),
                (int) ($row['smtp_port'] ?? 587),
                (string) ($row['smtp_tls'] ?? 'starttls'),
                $index + 1,
            );
        }

        usort($profiles, static fn (MailAccountProfile $a, MailAccountProfile $b): int => $a->label <=> $b->label);

        return $profiles;
    }

    public function save(string $mailboxIdentity, MailAccountProfile $profile): void
    {
        $rows = $this->rows($mailboxIdentity);
        $row = $this->row($profile);

        if ($profile->id !== null && array_key_exists($profile->id - 1, $rows)) {
            $rows[$profile->id - 1] = $row;
            $_SESSION['mail_account_profiles_by_mailbox'][$mailboxIdentity] = $rows;
            return;
        }

        $normalizedEmail = mb_strtolower($profile->email);
        foreach ($rows as $index => $existing) {
            if (mb_strtolower((string) ($existing['email'] ?? '')) !== $normalizedEmail) {
                continue;
            }

            $rows[$index] = $row;
            $_SESSION['mail_account_profiles_by_mailbox'][$mailboxIdentity] = $rows;
            return;
        }

        $_SESSION['mail_account_profiles_by_mailbox'][$mailboxIdentity][] = $row;
    }

    public function deleteForMailbox(string $mailboxIdentity, int $id): void
    {
        $allRows = $_SESSION['mail_account_profiles_by_mailbox'] ?? [];
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
        $_SESSION['mail_account_profiles_by_mailbox'][$mailboxIdentity] = array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $mailboxIdentity): array
    {
        $allRows = $_SESSION['mail_account_profiles_by_mailbox'] ?? [];
        if (!is_array($allRows)) {
            return [];
        }

        $rows = $allRows[$mailboxIdentity] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MailAccountProfile $profile): array
    {
        return [
            'label' => $profile->label,
            'email' => $profile->email,
            'imap_host' => $profile->imapHost,
            'imap_port' => $profile->imapPort,
            'imap_tls' => $profile->imapTls,
            'smtp_host' => $profile->smtpHost,
            'smtp_port' => $profile->smtpPort,
            'smtp_tls' => $profile->smtpTls,
        ];
    }
}

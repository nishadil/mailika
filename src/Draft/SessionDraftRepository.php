<?php

declare(strict_types=1);

namespace Mailika\Draft;

final class SessionDraftRepository implements DraftRepositoryInterface
{
    /**
     * @return list<DraftMessage>
     */
    public function listForMailbox(string $mailboxIdentity): array
    {
        $drafts = [];
        foreach ($this->rows($mailboxIdentity) as $row) {
            $drafts[] = $this->hydrate($row);
        }

        return array_reverse($drafts);
    }

    public function findForMailbox(string $mailboxIdentity, string $id): ?DraftMessage
    {
        foreach ($this->rows($mailboxIdentity) as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                return $this->hydrate($row);
            }
        }

        return null;
    }

    public function save(string $mailboxIdentity, DraftMessage $draft): void
    {
        if ($draft->id !== '' && $this->update($mailboxIdentity, $draft)) {
            return;
        }

        $_SESSION['drafts_by_mailbox'][$mailboxIdentity][] = [
            'id' => bin2hex(random_bytes(12)),
            'to' => $draft->to,
            'cc' => $draft->cc,
            'bcc' => $draft->bcc,
            'subject' => $draft->subject,
            'body' => $draft->body,
            'updated_at' => gmdate(DATE_ATOM),
            'identity_email' => $draft->identityEmail,
        ];
    }

    public function deleteForMailbox(string $mailboxIdentity, string $id): void
    {
        $allRows = $_SESSION['drafts_by_mailbox'] ?? [];
        if (!is_array($allRows)) {
            return;
        }

        $rows = $allRows[$mailboxIdentity] ?? [];
        if (!is_array($rows)) {
            return;
        }

        $_SESSION['drafts_by_mailbox'][$mailboxIdentity] = array_values(array_filter(
            $rows,
            static fn (mixed $row): bool => !is_array($row) || (string) ($row['id'] ?? '') !== $id,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $mailboxIdentity): array
    {
        $allRows = $_SESSION['drafts_by_mailbox'] ?? [];
        if (!is_array($allRows)) {
            return [];
        }

        $rows = $allRows[$mailboxIdentity] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    private function update(string $mailboxIdentity, DraftMessage $draft): bool
    {
        $rows = $_SESSION['drafts_by_mailbox'][$mailboxIdentity] ?? [];
        if (!is_array($rows)) {
            return false;
        }

        foreach ($rows as $index => $row) {
            if (!is_array($row) || (string) ($row['id'] ?? '') !== $draft->id) {
                continue;
            }

            $_SESSION['drafts_by_mailbox'][$mailboxIdentity][$index] = [
                'id' => $draft->id,
                'to' => $draft->to,
                'cc' => $draft->cc,
                'bcc' => $draft->bcc,
                'subject' => $draft->subject,
                'body' => $draft->body,
                'updated_at' => gmdate(DATE_ATOM),
                'identity_email' => $draft->identityEmail,
            ];

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DraftMessage
    {
        return new DraftMessage(
            (string) ($row['id'] ?? ''),
            (string) ($row['to'] ?? ''),
            (string) ($row['cc'] ?? ''),
            (string) ($row['bcc'] ?? ''),
            (string) ($row['subject'] ?? ''),
            (string) ($row['body'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
            isset($row['identity_email']) && $row['identity_email'] !== '' ? (string) $row['identity_email'] : null,
        );
    }
}

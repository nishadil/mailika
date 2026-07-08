<?php

declare(strict_types=1);

namespace Mailika\Contact;

final class SessionContactRepository implements ContactRepositoryInterface
{
    /**
     * @return list<Contact>
     */
    public function listForMailbox(string $mailboxIdentity): array
    {
        $rows = $this->rows($mailboxIdentity);
        $contacts = [];

        foreach ($rows as $index => $row) {
            $contacts[] = new Contact(
                (string) ($row['display_name'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['notes'] ?? ''),
                $index + 1,
                $this->groupNames($mailboxIdentity, $row),
            );
        }

        return $contacts;
    }

    public function save(string $mailboxIdentity, Contact $contact): void
    {
        $rows = $this->rows($mailboxIdentity);
        foreach ($rows as $index => $row) {
            if (strcasecmp((string) ($row['email'] ?? ''), $contact->email) !== 0) {
                continue;
            }

            $rows[$index] = [
                'display_name' => $contact->displayName,
                'email' => $contact->email,
                'notes' => $contact->notes,
                'group_ids' => $row['group_ids'] ?? [],
            ];
            $_SESSION['contacts_by_mailbox'][$mailboxIdentity] = $rows;
            return;
        }

        $_SESSION['contacts_by_mailbox'][$mailboxIdentity][] = [
            'display_name' => $contact->displayName,
            'email' => $contact->email,
            'notes' => $contact->notes,
        ];
    }

    public function deleteForMailbox(string $mailboxIdentity, string $email): void
    {
        $allRows = $_SESSION['contacts_by_mailbox'] ?? [];
        if (!is_array($allRows)) {
            return;
        }

        $rows = $allRows[$mailboxIdentity] ?? [];
        if (!is_array($rows)) {
            return;
        }

        $_SESSION['contacts_by_mailbox'][$mailboxIdentity] = array_values(array_filter(
            $rows,
            static fn (mixed $row): bool => !is_array($row)
                || strcasecmp((string) ($row['email'] ?? ''), $email) !== 0,
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function groupNames(string $mailboxIdentity, array $row): array
    {
        $groupIds = $row['group_ids'] ?? [];
        if (!is_array($groupIds)) {
            return [];
        }

        $groups = $_SESSION['contact_groups_by_mailbox'][$mailboxIdentity] ?? [];
        if (!is_array($groups)) {
            return [];
        }

        $names = [];
        foreach ($groups as $group) {
            if (!is_array($group) || !in_array((int) ($group['id'] ?? 0), $groupIds, true)) {
                continue;
            }

            $names[] = (string) ($group['name'] ?? '');
        }

        return array_values(array_filter($names));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $mailboxIdentity): array
    {
        $allRows = $_SESSION['contacts_by_mailbox'] ?? [];
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

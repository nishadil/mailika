<?php

declare(strict_types=1);

namespace Mailika\Contact;

final class SessionContactGroupRepository implements ContactGroupRepositoryInterface
{
    /**
     * @return list<ContactGroup>
     */
    public function listForMailbox(string $mailboxIdentity): array
    {
        $groups = [];
        foreach ($this->rows($mailboxIdentity) as $row) {
            $groups[] = new ContactGroup((string) ($row['name'] ?? ''), (int) ($row['id'] ?? 0));
        }

        return $groups;
    }

    public function save(string $mailboxIdentity, ContactGroup $group): void
    {
        $rows = $this->rows($mailboxIdentity);
        foreach ($rows as $index => $row) {
            if (strcasecmp((string) ($row['name'] ?? ''), $group->name) === 0) {
                $rows[$index]['name'] = $group->name;
                $_SESSION['contact_groups_by_mailbox'][$mailboxIdentity] = $rows;
                return;
            }
        }

        $_SESSION['contact_group_next_id'] = (int) ($_SESSION['contact_group_next_id'] ?? 0) + 1;
        $_SESSION['contact_groups_by_mailbox'][$mailboxIdentity][] = [
            'id' => $_SESSION['contact_group_next_id'],
            'name' => $group->name,
        ];
    }

    public function assignContactByEmail(string $mailboxIdentity, string $email, int $groupId): void
    {
        $contacts = $_SESSION['contacts_by_mailbox'][$mailboxIdentity] ?? [];
        if (!is_array($contacts)) {
            return;
        }

        for ($index = count($contacts) - 1; $index >= 0; $index--) {
            $row = $contacts[$index] ?? null;
            if (!is_array($row) || strcasecmp((string) ($row['email'] ?? ''), $email) !== 0) {
                continue;
            }

            $groupIds = $row['group_ids'] ?? [];
            $groupIds = is_array($groupIds) ? array_map('intval', $groupIds) : [];
            if (!in_array($groupId, $groupIds, true)) {
                $groupIds[] = $groupId;
            }

            $_SESSION['contacts_by_mailbox'][$mailboxIdentity][$index]['group_ids'] = $groupIds;
            return;
        }
    }

    public function deleteForMailbox(string $mailboxIdentity, int $groupId): void
    {
        $groups = $_SESSION['contact_groups_by_mailbox'][$mailboxIdentity] ?? [];
        if (!is_array($groups)) {
            return;
        }

        $found = false;
        $_SESSION['contact_groups_by_mailbox'][$mailboxIdentity] = array_values(array_filter(
            $groups,
            static function (mixed $row) use ($groupId, &$found): bool {
                if (!is_array($row) || (int) ($row['id'] ?? 0) !== $groupId) {
                    return true;
                }

                $found = true;
                return false;
            },
        ));

        if (!$found) {
            return;
        }

        $contacts = $_SESSION['contacts_by_mailbox'][$mailboxIdentity] ?? [];
        if (!is_array($contacts)) {
            return;
        }

        foreach ($contacts as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $groupIds = $row['group_ids'] ?? [];
            if (!is_array($groupIds)) {
                continue;
            }

            $_SESSION['contacts_by_mailbox'][$mailboxIdentity][$index]['group_ids'] = array_values(array_filter(
                array_map('intval', $groupIds),
                static fn (int $id): bool => $id !== $groupId,
            ));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $mailboxIdentity): array
    {
        $allRows = $_SESSION['contact_groups_by_mailbox'] ?? [];
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

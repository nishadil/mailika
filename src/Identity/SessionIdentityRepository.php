<?php

declare(strict_types=1);

namespace Mailika\Identity;

final class SessionIdentityRepository implements IdentityRepositoryInterface
{
    /**
     * @return list<Identity>
     */
    public function listForMailbox(string $mailboxIdentity): array
    {
        $rows = $this->rows($mailboxIdentity);
        $identities = [];

        foreach ($rows as $index => $row) {
            $identities[] = new Identity(
                (string) ($row['display_name'] ?? ''),
                (string) ($row['email'] ?? ''),
                isset($row['reply_to']) && $row['reply_to'] !== '' ? (string) $row['reply_to'] : null,
                (bool) ($row['default'] ?? false),
                $index + 1,
            );
        }

        usort($identities, static function (Identity $a, Identity $b): int {
            if ($a->default !== $b->default) {
                return $a->default ? -1 : 1;
            }

            return [$a->displayName, $a->email] <=> [$b->displayName, $b->email];
        });

        return $identities;
    }

    public function save(string $mailboxIdentity, Identity $identity): void
    {
        $rows = $this->rows($mailboxIdentity);
        if ($identity->default) {
            foreach ($rows as $index => $_row) {
                $rows[$index]['default'] = false;
            }
        }

        foreach ($rows as $index => $row) {
            if (strcasecmp((string) ($row['email'] ?? ''), $identity->email) !== 0) {
                continue;
            }

            $rows[$index] = [
                'display_name' => $identity->displayName,
                'email' => $identity->email,
                'reply_to' => $identity->replyTo,
                'default' => $identity->default,
            ];
            $_SESSION['identities_by_mailbox'][$mailboxIdentity] = $rows;
            $this->ensureDefault($mailboxIdentity);
            return;
        }

        $rows[] = [
            'display_name' => $identity->displayName,
            'email' => $identity->email,
            'reply_to' => $identity->replyTo,
            'default' => $identity->default,
        ];
        $_SESSION['identities_by_mailbox'][$mailboxIdentity] = $rows;
        $this->ensureDefault($mailboxIdentity);
    }

    public function deleteForMailbox(string $mailboxIdentity, int $id): void
    {
        $allRows = $_SESSION['identities_by_mailbox'] ?? [];
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
        $_SESSION['identities_by_mailbox'][$mailboxIdentity] = array_values(array_filter($rows, 'is_array'));
        $this->ensureDefault($mailboxIdentity);
    }

    private function ensureDefault(string $mailboxIdentity): void
    {
        $rows = $_SESSION['identities_by_mailbox'][$mailboxIdentity] ?? [];
        if (!is_array($rows) || $rows === []) {
            return;
        }

        foreach ($rows as $row) {
            if (is_array($row) && (bool) ($row['default'] ?? false)) {
                return;
            }
        }

        $_SESSION['identities_by_mailbox'][$mailboxIdentity][0]['default'] = true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $mailboxIdentity): array
    {
        $allRows = $_SESSION['identities_by_mailbox'] ?? [];
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

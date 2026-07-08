<?php

declare(strict_types=1);

namespace Mailika\Preferences;

final class SessionPreferencesRepository implements PreferencesRepositoryInterface
{
    public function get(string $mailboxIdentity): Preferences
    {
        $rows = $_SESSION['preferences_by_mailbox'] ?? [];
        if (!is_array($rows)) {
            return new Preferences();
        }

        $row = $rows[$mailboxIdentity] ?? [];
        if (!is_array($row)) {
            return new Preferences();
        }

        return new Preferences(
            (string) ($row['locale'] ?? 'en'),
            (string) ($row['timezone'] ?? 'UTC'),
            (bool) ($row['remote_images'] ?? false),
            (string) ($row['theme'] ?? 'system'),
            (bool) ($row['threaded_listing'] ?? true),
            $this->messagesPerPage($row['messages_per_page'] ?? 50),
        );
    }

    public function save(string $mailboxIdentity, Preferences $preferences): void
    {
        $_SESSION['preferences_by_mailbox'][$mailboxIdentity] = [
            'locale' => $preferences->locale,
            'timezone' => $preferences->timezone,
            'remote_images' => $preferences->remoteImages,
            'theme' => $preferences->theme,
            'threaded_listing' => $preferences->threadedListing,
            'messages_per_page' => $preferences->messagesPerPage,
        ];
    }

    private function messagesPerPage(mixed $value): int
    {
        $perPage = is_numeric($value) ? (int) $value : 50;
        return in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
    }
}

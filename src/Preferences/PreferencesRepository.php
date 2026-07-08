<?php

declare(strict_types=1);

namespace Mailika\Preferences;

use PDO;

final readonly class PreferencesRepository implements PreferencesRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function get(string $mailboxIdentity): Preferences
    {
        $statement = $this->pdo->prepare(
            'SELECT locale, timezone, remote_images, theme, threaded_listing, messages_per_page
             FROM preferences WHERE mailbox_identity = :mailbox',
        );
        $statement->execute(['mailbox' => $mailboxIdentity]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return new Preferences();
        }

        return new Preferences(
            (string) $row['locale'],
            (string) $row['timezone'],
            $this->boolValue($row['remote_images'] ?? false),
            (string) ($row['theme'] ?? 'system'),
            $this->boolValue($row['threaded_listing'] ?? true),
            $this->messagesPerPage($row['messages_per_page'] ?? 50),
        );
    }

    public function save(string $mailboxIdentity, Preferences $preferences): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO preferences
               (mailbox_identity, locale, timezone, remote_images, theme, threaded_listing,
                messages_per_page, updated_at)
             VALUES
               (:mailbox, :locale, :timezone, :remote_images, :theme, :threaded_listing,
                :messages_per_page, CURRENT_TIMESTAMP)
             ON CONFLICT (mailbox_identity) DO UPDATE SET
               locale = EXCLUDED.locale,
               timezone = EXCLUDED.timezone,
               remote_images = EXCLUDED.remote_images,
               theme = EXCLUDED.theme,
               threaded_listing = EXCLUDED.threaded_listing,
               messages_per_page = EXCLUDED.messages_per_page,
               updated_at = CURRENT_TIMESTAMP',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'locale' => $preferences->locale,
            'timezone' => $preferences->timezone,
            'remote_images' => $preferences->remoteImages ? 1 : 0,
            'theme' => $preferences->theme,
            'threaded_listing' => $preferences->threadedListing ? 1 : 0,
            'messages_per_page' => $preferences->messagesPerPage,
        ]);
    }

    private function boolValue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private function messagesPerPage(mixed $value): int
    {
        $perPage = is_numeric($value) ? (int) $value : 50;
        return in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
    }
}

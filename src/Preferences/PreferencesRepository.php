<?php

declare(strict_types=1);

namespace Mailika\Preferences;

use PDO;

final readonly class PreferencesRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function get(string $mailboxIdentity): Preferences
    {
        $statement = $this->pdo->prepare(
            'SELECT locale, timezone, remote_images FROM preferences WHERE mailbox_identity = :mailbox',
        );
        $statement->execute(['mailbox' => $mailboxIdentity]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return new Preferences();
        }

        return new Preferences(
            (string) $row['locale'],
            (string) $row['timezone'],
            (bool) $row['remote_images'],
        );
    }

    public function save(string $mailboxIdentity, Preferences $preferences): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO preferences (mailbox_identity, locale, timezone, remote_images, updated_at)
             VALUES (:mailbox, :locale, :timezone, :remote_images, CURRENT_TIMESTAMP)
             ON CONFLICT (mailbox_identity) DO UPDATE SET
               locale = EXCLUDED.locale,
               timezone = EXCLUDED.timezone,
               remote_images = EXCLUDED.remote_images,
               updated_at = CURRENT_TIMESTAMP',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'locale' => $preferences->locale,
            'timezone' => $preferences->timezone,
            'remote_images' => $preferences->remoteImages ? 1 : 0,
        ]);
    }
}

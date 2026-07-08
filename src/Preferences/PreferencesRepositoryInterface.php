<?php

declare(strict_types=1);

namespace Mailika\Preferences;

interface PreferencesRepositoryInterface
{
    public function get(string $mailboxIdentity): Preferences;

    public function save(string $mailboxIdentity, Preferences $preferences): void;
}

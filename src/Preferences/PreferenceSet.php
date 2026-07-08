<?php

declare(strict_types=1);

namespace Mailika\Preferences;

final readonly class PreferenceSet
{
    public function __construct(
        public string $locale = 'en',
        public string $timezone = 'UTC',
        public bool $remoteImages = false,
        public string $theme = 'system',
        public bool $threadedListing = true,
        public int $messagesPerPage = 50,
    ) {
    }
}

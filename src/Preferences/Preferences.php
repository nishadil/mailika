<?php

declare(strict_types=1);

namespace Mailika\Preferences;

final readonly class Preferences
{
    public function __construct(
        public string $locale = 'en',
        public string $timezone = 'UTC',
        public bool $remoteImages = false,
    ) {
    }
}

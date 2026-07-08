<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class MailboxFolder
{
    /**
     * @param list<string> $specialUse
     */
    public function __construct(
        public string $name,
        public string $displayName,
        public string $delimiter = '/',
        public int $unread = 0,
        public bool $selectable = true,
        public bool $hasChildren = false,
        public array $specialUse = [],
    ) {
    }
}

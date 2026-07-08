<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class MailboxCapabilities
{
    /**
     * @param list<string> $raw
     */
    public function __construct(
        public bool $move = false,
        public bool $quota = false,
        public bool $acl = false,
        public bool $idle = false,
        public bool $sort = false,
        public bool $thread = false,
        public array $raw = [],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class MailboxQuota
{
    public function __construct(
        public ?int $usedBytes = null,
        public ?int $limitBytes = null,
    ) {
    }
}

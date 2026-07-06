<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class MessageSummary
{
    public function __construct(
        public string $id,
        public string $from,
        public string $subject,
        public string $date,
        public bool $seen,
        public bool $hasAttachments,
    ) {
    }
}

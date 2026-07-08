<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class MessageEnvelope
{
    public function __construct(
        public string $id,
        public string $from,
        public string $subject,
        public string $date,
        public bool $seen,
        public bool $hasAttachments,
        public bool $flagged = false,
        public bool $answered = false,
        public bool $deleted = false,
        public bool $draft = false,
        public ?string $threadId = null,
    ) {
    }
}

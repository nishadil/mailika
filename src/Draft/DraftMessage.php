<?php

declare(strict_types=1);

namespace Mailika\Draft;

final readonly class DraftMessage
{
    public function __construct(
        public string $id,
        public string $to,
        public string $cc,
        public string $bcc,
        public string $subject,
        public string $body,
        public string $updatedAt,
        public ?string $identityEmail = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class Attachment
{
    public function __construct(
        public string $filename,
        public string $contentType,
        public int $bytes,
    ) {
    }
}

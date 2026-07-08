<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class Attachment
{
    public function __construct(
        public string $filename,
        public string $contentType,
        public int $bytes,
        public ?string $id = null,
        public bool $inline = false,
        public ?string $contentId = null,
    ) {
    }
}

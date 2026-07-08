<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class MessageAttachment
{
    public function __construct(
        public string $id,
        public string $filename,
        public string $contentType,
        public int $bytes,
        public string $content = '',
        public bool $inline = false,
        public ?string $contentId = null,
    ) {
    }
}

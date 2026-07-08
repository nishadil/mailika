<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class MessageBody
{
    /**
     * @param list<MessageAttachment> $attachments
     */
    public function __construct(
        public string $text,
        public string $html,
        public array $attachments = [],
    ) {
    }
}

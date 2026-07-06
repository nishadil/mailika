<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class Message
{
    /**
     * @param list<Attachment> $attachments
     */
    public function __construct(
        public string $id,
        public string $from,
        public string $to,
        public string $subject,
        public string $date,
        public string $htmlBody,
        public string $textBody,
        public array $attachments = [],
    ) {
    }
}

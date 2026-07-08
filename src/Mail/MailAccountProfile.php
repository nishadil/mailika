<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class MailAccountProfile
{
    public function __construct(
        public string $label,
        public string $email,
        public string $imapHost,
        public int $imapPort,
        public bool $imapTls,
        public string $smtpHost,
        public int $smtpPort,
        public string $smtpTls,
        public ?int $id = null,
    ) {
    }
}

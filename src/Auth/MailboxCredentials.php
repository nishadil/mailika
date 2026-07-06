<?php

declare(strict_types=1);

namespace Mailika\Auth;

final readonly class MailboxCredentials
{
    public function __construct(
        public string $email,
        public string $password,
        public string $imapHost,
        public int $imapPort,
        public bool $imapTls,
        public string $smtpHost,
        public int $smtpPort,
        public string $smtpTls,
    ) {
    }

    public function identity(): string
    {
        return strtolower($this->email . '@' . $this->imapHost);
    }
}

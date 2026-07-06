<?php

declare(strict_types=1);

namespace Mailika\Mail;

use Mailika\Auth\MailboxCredentials;

interface SmtpSenderInterface
{
    /**
     * @param list<string> $to
     * @param list<array{path:string,name:string,mime:string}> $attachments
     */
    public function send(
        MailboxCredentials $credentials,
        array $to,
        string $subject,
        string $plainBody,
        string $htmlBody,
        array $attachments = [],
    ): void;
}

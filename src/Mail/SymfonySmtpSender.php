<?php

declare(strict_types=1);

namespace Mailika\Mail;

use Mailika\Auth\MailboxCredentials;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

final class SymfonySmtpSender implements SmtpSenderInterface
{
    public function send(
        MailboxCredentials $credentials,
        array $to,
        string $subject,
        string $plainBody,
        string $htmlBody,
        array $attachments = [],
    ): void {
        $scheme = $credentials->smtpTls === 'smtps' ? 'smtps' : 'smtp';
        $dsn = sprintf(
            '%s://%s:%s@%s:%d',
            $scheme,
            rawurlencode($credentials->email),
            rawurlencode($credentials->password),
            $credentials->smtpHost,
            $credentials->smtpPort,
        );

        if ($credentials->smtpTls === 'starttls') {
            $dsn .= '?encryption=tls';
        }

        $email = (new Email())
            ->from($credentials->email)
            ->to(...$to)
            ->subject($subject)
            ->text($plainBody)
            ->html($htmlBody);

        foreach ($attachments as $attachment) {
            $email->attachFromPath($attachment['path'], $attachment['name'], $attachment['mime']);
        }

        Transport::fromDsn($dsn)->send($email);
    }
}

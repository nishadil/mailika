<?php

declare(strict_types=1);

namespace Mailika\Mail;

use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Validation\Validator;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\InvalidArgumentException as MimeInvalidArgumentException;

final class SymfonySmtpSender implements SmtpSenderInterface
{
    public function __construct(private ?Config $config = null)
    {
    }

    public function send(
        MailboxCredentials $credentials,
        array $to,
        string $subject,
        string $plainBody,
        string $htmlBody,
        array $attachments = [],
    ): void {
        $this->sendEnvelope($credentials, new SendEnvelope($to, [], [], $subject, $plainBody, $htmlBody, $attachments));
    }

    public function sendEnvelope(MailboxCredentials $credentials, SendEnvelope $envelope): void
    {
        if (!in_array($credentials->smtpTls, ['starttls', 'smtps', 'none'], true)) {
            throw SmtpDeliveryException::invalidSecurityMode();
        }

        $this->validateTransportPolicy($credentials);
        $this->validateEnvelope($credentials, $envelope);

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
            ->from($this->senderAddress($credentials, $envelope))
            ->to(...$this->recipientList($envelope->to))
            ->subject($envelope->subject)
            ->text($envelope->plainBody)
            ->html($envelope->htmlBody);

        if ($envelope->cc !== []) {
            $email = $email->cc(...$this->recipientList($envelope->cc));
        }

        if ($envelope->bcc !== []) {
            $email = $email->bcc(...$this->recipientList($envelope->bcc));
        }

        if ($envelope->replyToEmail !== null && $envelope->replyToEmail !== '') {
            $email = $email->replyTo($this->address($envelope->replyToEmail));
        }

        if (
            $envelope->replyToMessageId !== null
            && $envelope->replyToMessageId !== ''
            && strlen($envelope->replyToMessageId) <= 255
            && Validator::headerText($envelope->replyToMessageId)
        ) {
            $messageId = $this->bracketedMessageId($envelope->replyToMessageId);
            $email->getHeaders()->addTextHeader('In-Reply-To', $messageId);
            $email->getHeaders()->addTextHeader('References', $messageId);
        }

        foreach ($envelope->attachments as $attachment) {
            $email->attachFromPath($attachment['path'], $attachment['name'], $attachment['mime']);
        }

        try {
            Transport::fromDsn($dsn)->send($email);
        } catch (MimeInvalidArgumentException $exception) {
            if ($envelope->requiresSmtpUtf8) {
                throw SmtpDeliveryException::smtpUtf8Unsupported($exception);
            }

            throw $exception;
        }
    }

    private function bracketedMessageId(string $messageId): string
    {
        $messageId = trim($messageId, '<> ');

        return '<' . $messageId . '>';
    }

    private function validateEnvelope(MailboxCredentials $credentials, SendEnvelope $envelope): void
    {
        if (
            !Validator::email($credentials->email)
            || !Validator::email($this->senderAddress($credentials, $envelope))
        ) {
            throw SmtpDeliveryException::invalidEnvelopeData();
        }

        if ($envelope->to === [] || !$this->validRecipients($envelope->to, $envelope->cc, $envelope->bcc)) {
            throw SmtpDeliveryException::invalidEnvelopeData();
        }

        if (
            $envelope->replyToEmail !== null
            && $envelope->replyToEmail !== ''
            && !Validator::email($envelope->replyToEmail)
        ) {
            throw SmtpDeliveryException::invalidEnvelopeData();
        }

        if (
            $envelope->subject === ''
            || strlen($envelope->subject) > 255
            || !Validator::headerText($envelope->subject)
        ) {
            throw SmtpDeliveryException::invalidEnvelopeData();
        }

        if (
            $envelope->replyToMessageId !== null
            && $envelope->replyToMessageId !== ''
            && (strlen($envelope->replyToMessageId) > 255 || !Validator::headerText($envelope->replyToMessageId))
        ) {
            throw SmtpDeliveryException::invalidEnvelopeData();
        }

        foreach ($envelope->attachments as $attachment) {
            if (
                $attachment['path'] === ''
                || $attachment['name'] === ''
                || !Validator::headerText($attachment['name'])
                || !Validator::headerText($attachment['mime'])
                || preg_match('/^[A-Za-z0-9.+-]+\/[A-Za-z0-9.+-]+$/', $attachment['mime']) !== 1
            ) {
                throw SmtpDeliveryException::invalidEnvelopeData();
            }
        }
    }

    private function validateTransportPolicy(MailboxCredentials $credentials): void
    {
        if (!Validator::tcpPort($credentials->smtpPort)) {
            throw SmtpDeliveryException::transportPolicyViolation();
        }

        if ($this->config === null) {
            return;
        }

        if (!Validator::hostAllowed($credentials->smtpHost, $this->config->stringList('mail.allowed_smtp_hosts'))) {
            throw SmtpDeliveryException::transportPolicyViolation();
        }

        if ($this->config->bool('mail.require_tls', true) && $credentials->smtpTls === 'none') {
            throw SmtpDeliveryException::transportPolicyViolation();
        }
    }

    private function senderAddress(MailboxCredentials $credentials, SendEnvelope $envelope): string
    {
        return $this->address($envelope->identityEmail ?: $credentials->email);
    }

    /**
     * @param list<string> ...$lists
     */
    private function validRecipients(array ...$lists): bool
    {
        foreach ($lists as $list) {
            foreach ($list as $address) {
                if (!Validator::email($address)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param list<string> $addresses
     * @return list<string>
     */
    private function recipientList(array $addresses): array
    {
        return array_map(fn (string $address): string => $this->address($address), $addresses);
    }

    private function address(string $address): string
    {
        return Validator::normalizeEmail($address);
    }
}

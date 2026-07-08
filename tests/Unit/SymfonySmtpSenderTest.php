<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Mail\SendEnvelope;
use Mailika\Mail\SmtpDeliveryException;
use Mailika\Mail\SymfonySmtpSender;
use PHPUnit\Framework\TestCase;

final class SymfonySmtpSenderTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['MAILIKA_REQUIRE_TLS'], $_ENV['MAILIKA_ALLOWED_SMTP_HOSTS']);
    }

    public function testRejectsUnknownSmtpSecurityModeBeforeTransportCreation(): void
    {
        $sender = new SymfonySmtpSender();
        $credentials = new MailboxCredentials(
            'smoke@example.com',
            'secret',
            'imap.example.com',
            993,
            true,
            'smtp.example.com',
            587,
            'implicit',
        );

        $this->expectException(SmtpDeliveryException::class);
        $this->expectExceptionMessage('SMTP security mode must be starttls, smtps, or none.');

        $sender->sendEnvelope($credentials, new SendEnvelope(
            ['recipient@example.com'],
            [],
            [],
            'Subject',
            'Body',
            '<p>Body</p>',
        ));
    }

    public function testRejectsInvalidRecipientsBeforeTransportCreation(): void
    {
        $this->expectException(SmtpDeliveryException::class);
        $this->expectExceptionMessage('Message contains invalid sender, recipient, or header data.');

        (new SymfonySmtpSender())->sendEnvelope($this->credentials(), new SendEnvelope(
            ["victim@example.com\r\nBcc: attacker@example.com"],
            [],
            [],
            'Subject',
            'Body',
            '<p>Body</p>',
        ));
    }

    public function testRejectsInjectedSubjectBeforeTransportCreation(): void
    {
        $this->expectException(SmtpDeliveryException::class);
        $this->expectExceptionMessage('Message contains invalid sender, recipient, or header data.');

        (new SymfonySmtpSender())->sendEnvelope($this->credentials(), new SendEnvelope(
            ['recipient@example.com'],
            [],
            [],
            "Quarterly report\r\nBcc: attacker@example.com",
            'Body',
            '<p>Body</p>',
        ));
    }

    public function testRejectsInjectedReplyMessageIdBeforeTransportCreation(): void
    {
        $this->expectException(SmtpDeliveryException::class);
        $this->expectExceptionMessage('Message contains invalid sender, recipient, or header data.');

        (new SymfonySmtpSender())->sendEnvelope($this->credentials(), new SendEnvelope(
            ['recipient@example.com'],
            [],
            [],
            'Subject',
            'Body',
            '<p>Body</p>',
            replyToMessageId: "source@example.com\r\nReferences: attacker@example.com",
        ));
    }

    public function testRejectsInvalidAttachmentHeadersBeforeTransportCreation(): void
    {
        $this->expectException(SmtpDeliveryException::class);
        $this->expectExceptionMessage('Message contains invalid sender, recipient, or header data.');

        (new SymfonySmtpSender())->sendEnvelope($this->credentials(), new SendEnvelope(
            ['recipient@example.com'],
            [],
            [],
            'Subject',
            'Body',
            '<p>Body</p>',
            [['path' => '/tmp/a', 'name' => "safe.txt\r\nX-Test: 1", 'mime' => 'text/plain']],
        ));
    }

    public function testRejectsPlainSmtpWhenDeploymentRequiresTls(): void
    {
        $_ENV['MAILIKA_REQUIRE_TLS'] = 'true';
        $_ENV['MAILIKA_ALLOWED_SMTP_HOSTS'] = '*';

        $this->expectException(SmtpDeliveryException::class);
        $this->expectExceptionMessage('SMTP transport settings are not allowed in this deployment.');

        (new SymfonySmtpSender($this->config()))->sendEnvelope(
            new MailboxCredentials(
                'smoke@example.com',
                'secret',
                'imap.example.com',
                993,
                true,
                'smtp.example.com',
                25,
                'none',
            ),
            new SendEnvelope(['recipient@example.com'], [], [], 'Subject', 'Body', '<p>Body</p>'),
        );
    }

    public function testRejectsDisallowedSmtpHostBeforeTransportCreation(): void
    {
        $_ENV['MAILIKA_REQUIRE_TLS'] = 'true';
        $_ENV['MAILIKA_ALLOWED_SMTP_HOSTS'] = 'smtp.allowed.example';

        $this->expectException(SmtpDeliveryException::class);
        $this->expectExceptionMessage('SMTP transport settings are not allowed in this deployment.');

        (new SymfonySmtpSender($this->config()))->sendEnvelope(
            $this->credentials(),
            new SendEnvelope(['recipient@example.com'], [], [], 'Subject', 'Body', '<p>Body</p>'),
        );
    }

    public function testRejectsInvalidSmtpPortBeforeTransportCreation(): void
    {
        $this->expectException(SmtpDeliveryException::class);
        $this->expectExceptionMessage('SMTP transport settings are not allowed in this deployment.');

        (new SymfonySmtpSender())->sendEnvelope(
            new MailboxCredentials(
                'smoke@example.com',
                'secret',
                'imap.example.com',
                993,
                true,
                'smtp.example.com',
                0,
                'starttls',
            ),
            new SendEnvelope(['recipient@example.com'], [], [], 'Subject', 'Body', '<p>Body</p>'),
        );
    }

    private function credentials(): MailboxCredentials
    {
        return new MailboxCredentials(
            'smoke@example.com',
            'secret',
            'imap.example.com',
            993,
            true,
            'smtp.example.com',
            587,
            'starttls',
        );
    }

    private function config(): Config
    {
        return Config::fromEnvironment(dirname(__DIR__, 2));
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Mail\SendEnvelope;
use PHPUnit\Framework\TestCase;

final class SendEnvelopeTest extends TestCase
{
    public function testCarriesCcBccIdentityAndAttachments(): void
    {
        $envelope = new SendEnvelope(
            ['to@example.com'],
            ['cc@example.com'],
            ['bcc@example.com'],
            'Subject',
            'Body',
            '<p>Body</p>',
            [['path' => '/tmp/a', 'name' => 'a.txt', 'mime' => 'text/plain']],
            'identity@example.com',
            null,
            'reply@example.com',
        );

        self::assertSame(['cc@example.com'], $envelope->cc);
        self::assertSame(['bcc@example.com'], $envelope->bcc);
        self::assertSame('identity@example.com', $envelope->identityEmail);
        self::assertSame('reply@example.com', $envelope->replyToEmail);
        self::assertSame('a.txt', $envelope->attachments[0]['name']);
        self::assertFalse($envelope->requiresSmtpUtf8);
    }

    public function testDetectsSmtpUtf8RequirementFromUnicodeLocalPart(): void
    {
        $envelope = new SendEnvelope(
            ['büro@xn--bcher-kva.example'],
            [],
            [],
            'Subject',
            'Body',
            '<p>Body</p>',
        );

        self::assertTrue($envelope->requiresSmtpUtf8);
    }

    public function testIdnaDomainOnlyDoesNotRequireSmtpUtf8(): void
    {
        $envelope = new SendEnvelope(
            ['user@xn--bcher-kva.example'],
            [],
            [],
            'Subject',
            'Body',
            '<p>Body</p>',
        );

        self::assertFalse($envelope->requiresSmtpUtf8);
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Tests\Integration;

use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Mail\MessageEnvelope;
use Mailika\Mail\MessageSearchCriteria;
use Mailika\Mail\SendEnvelope;
use Mailika\Mail\SymfonySmtpSender;
use Mailika\Mail\WebklexMailboxClient;
use PHPUnit\Framework\TestCase;

final class ExternalMailFixtureTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('MAILIKA_EXTERNAL_MAIL_TESTS') !== '1') {
            self::markTestSkipped('Set MAILIKA_EXTERNAL_MAIL_TESTS=1 and start the GreenMail fixture to run.');
        }

        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('m', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['MAILIKA_REQUIRE_TLS'] = 'false';
        $_ENV['MAILIKA_ALLOWED_IMAP_HOSTS'] = '*';
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_KEY'], $_ENV['MAILIKA_REQUIRE_TLS'], $_ENV['MAILIKA_ALLOWED_IMAP_HOSTS']);
    }

    public function testWebklexAndSymfonyMailerAgainstDockerizedImapSmtpFixture(): void
    {
        $host = getenv('MAILIKA_TEST_MAIL_HOST') ?: '127.0.0.1';
        $smtpPort = $this->envInt('MAILIKA_TEST_SMTP_PORT', 3025);
        $imapPort = $this->envInt('MAILIKA_TEST_IMAP_PORT', 3143);

        if (!$this->portOpen($host, $smtpPort) || !$this->portOpen($host, $imapPort)) {
            self::markTestSkipped('GreenMail fixture is not reachable on the configured SMTP/IMAP ports.');
        }

        $config = Config::fromEnvironment(dirname(__DIR__, 2));
        $credentials = new MailboxCredentials(
            'mailika-' . bin2hex(random_bytes(4)) . '@localhost',
            'secret',
            $host,
            $imapPort,
            false,
            $host,
            $smtpPort,
            'none',
        );
        $attachmentPath = tempnam(sys_get_temp_dir(), 'mailika-attachment-');
        self::assertIsString($attachmentPath);
        file_put_contents($attachmentPath, 'Attachment body from external fixture test.');

        try {
            (new SymfonySmtpSender())->sendEnvelope(
                $credentials,
                new SendEnvelope(
                    [$credentials->email],
                    [],
                    [],
                    'Mailika external fixture ' . bin2hex(random_bytes(3)),
                    'Plain body from external fixture test.',
                    '<p>HTML body from <strong>external fixture</strong> test.</p>',
                    [['path' => $attachmentPath, 'name' => 'fixture.txt', 'mime' => 'text/plain']],
                    $credentials->email,
                ),
            );

            $mailbox = new WebklexMailboxClient($config);
            $message = $this->waitForMessage($mailbox, $credentials);

            $fullMessage = $mailbox->message($credentials, 'INBOX', $message->id);
            self::assertStringContainsString('external fixture', $fullMessage->textBody . $fullMessage->htmlBody);
            self::assertSame('fixture.txt', $fullMessage->attachments[0]->filename);

            $mailbox->markSeen($credentials, 'INBOX', $message->id, true);
            $mailbox->flag($credentials, 'INBOX', $message->id, true);
            $mailbox->createFolder($credentials, 'Archive');
            $mailbox->copy($credentials, 'INBOX', $message->id, 'Archive');
            $mailbox->move($credentials, 'INBOX', $message->id, 'Archive');

            $archiveMessage = $this->waitForMessage($mailbox, $credentials, 'Archive');
            self::assertTrue($archiveMessage->seen);
            self::assertTrue($archiveMessage->flagged);

            $mailbox->delete($credentials, 'Archive', $archiveMessage->id);
        } finally {
            @unlink($attachmentPath);
        }
    }

    private function envInt(string $key, int $default): int
    {
        $value = getenv($key);
        return is_string($value) && is_numeric($value) ? (int) $value : $default;
    }

    private function portOpen(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $error, 1.0);
        if ($connection === false) {
            return false;
        }

        fclose($connection);
        return true;
    }

    private function waitForMessage(
        WebklexMailboxClient $mailbox,
        MailboxCredentials $credentials,
        string $folder = 'INBOX',
    ): MessageEnvelope {
        $deadline = microtime(true) + 10.0;

        do {
            $messages = $mailbox->search(
                $credentials,
                new MessageSearchCriteria($folder, 'external fixture', 1, 10),
            );

            if ($messages !== []) {
                return $messages[0];
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for the external fixture message.');
    }
}

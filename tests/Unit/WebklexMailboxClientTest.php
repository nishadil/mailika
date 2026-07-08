<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Config\Config;
use Mailika\Mail\MessageAttachment;
use Mailika\Mail\WebklexMailboxClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Webklex\PHPIMAP\Attachment as WebklexAttachment;
use Webklex\PHPIMAP\Message as WebklexMessage;
use Webklex\PHPIMAP\Support\AttachmentCollection;

final class WebklexMailboxClientTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('w', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['MAILIKA_MAX_ATTACHMENT_BYTES'] = '1024';
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_KEY'], $_ENV['MAILIKA_MAX_ATTACHMENT_BYTES']);
    }

    public function testOversizedAttachmentDoesNotMaterializeContent(): void
    {
        $webklexAttachment = $this->webklexAttachment(2048, str_repeat('x', 2048));
        $attachments = $this->attachmentsFromMessage($webklexAttachment, true);

        self::assertSame(0, $webklexAttachment->contentReads);
        self::assertSame(2048, $attachments[0]->bytes);
        self::assertSame('', $attachments[0]->content);
    }

    public function testWithinLimitAttachmentCanMaterializeContent(): void
    {
        $webklexAttachment = $this->webklexAttachment(512, 'payload');
        $attachments = $this->attachmentsFromMessage($webklexAttachment, true);

        self::assertSame(1, $webklexAttachment->contentReads);
        self::assertSame(512, $attachments[0]->bytes);
        self::assertSame('payload', $attachments[0]->content);
    }

    public function testSingleAttachmentLookupOnlyMaterializesMatchedPart(): void
    {
        $unmatched = $this->webklexAttachment(128, 'not-this-one', 'unmatched');
        $matched = $this->webklexAttachment(128, 'matched', 'matched');
        $attachment = $this->attachmentFromMessage(
            [$unmatched, $matched],
            hash('sha256', 'matched'),
        );

        self::assertSame(0, $unmatched->contentReads);
        self::assertSame(1, $matched->contentReads);
        self::assertSame('matched', $attachment->content);
    }

    public function testDeleteRetriesWithoutExpungeWhenServerRejectsExpunge(): void
    {
        $message = $this->deletableMessage('NO EXPUNGE failed. Command not valid in this state');

        $this->deleteMessage($message);

        self::assertSame([true, false], $message->deleteCalls);
    }

    public function testDeleteKeepsNonExpungeFailuresVisible(): void
    {
        $message = $this->deletableMessage('NO STORE failed');

        $this->expectExceptionMessage('NO STORE failed');

        $this->deleteMessage($message);
    }

    /**
     * @return list<MessageAttachment>
     */
    private function attachmentsFromMessage(WebklexAttachment $attachment, bool $includeContent): array
    {
        $client = new WebklexMailboxClient(Config::fromEnvironment(dirname(__DIR__, 2)));
        $method = new ReflectionMethod(WebklexMailboxClient::class, 'attachmentsFromMessage');
        $method->setAccessible(true);

        /** @var list<MessageAttachment> $attachments */
        $attachments = $method->invoke($client, $this->webklexMessage($attachment), $includeContent);

        return $attachments;
    }

    /**
     * @param list<WebklexAttachment> $attachments
     */
    private function attachmentFromMessage(array $attachments, string $attachmentId): MessageAttachment
    {
        $client = new WebklexMailboxClient(Config::fromEnvironment(dirname(__DIR__, 2)));
        $method = new ReflectionMethod(WebklexMailboxClient::class, 'attachmentFromMessage');
        $method->setAccessible(true);

        /** @var MessageAttachment $attachment */
        $attachment = $method->invoke($client, $this->webklexMessage($attachments), $attachmentId);

        return $attachment;
    }

    /**
     * @param WebklexMessage&object{deleteCalls:list<bool>} $message
     */
    private function deleteMessage(WebklexMessage $message): void
    {
        $client = new WebklexMailboxClient(Config::fromEnvironment(dirname(__DIR__, 2)));
        $method = new ReflectionMethod(WebklexMailboxClient::class, 'deleteMessage');
        $method->setAccessible(true);
        $method->invoke($client, $message);
    }

    /**
     * @return WebklexMessage&object{deleteCalls:list<bool>}
     */
    private function deletableMessage(string $failure): WebklexMessage
    {
        return new class ($failure) extends WebklexMessage {
            /**
             * @var list<bool>
             */
            public array $deleteCalls = [];

            public function __construct(private readonly string $failure)
            {
            }

            public function delete(bool $expunge = true, ?string $trash_path = null, bool $force_move = false): bool
            {
                $this->deleteCalls[] = $expunge;
                if ($expunge) {
                    throw new \RuntimeException($this->failure);
                }

                return true;
            }
        };
    }

    /**
     * @return WebklexAttachment&object{contentReads:int}
     */
    private function webklexAttachment(int $bytes, string $content, string $hash = 'attachment-hash'): WebklexAttachment
    {
        return new class ($bytes, $content, $hash) extends WebklexAttachment {
            public int $contentReads = 0;

            public function __construct(
                private readonly int $bytes,
                private readonly string $content,
                private readonly string $hash,
            ) {
            }

            public function getHash(): string
            {
                return $this->hash;
            }

            public function getPartNumber(): int
            {
                return 1;
            }

            public function getName(): string
            {
                return 'large.bin';
            }

            public function getMimeType(): string
            {
                return 'application/octet-stream';
            }

            public function getContentType(): string
            {
                return 'application/octet-stream';
            }

            public function getSize(): int
            {
                return $this->bytes;
            }

            public function getContent(): string
            {
                $this->contentReads++;

                return $this->content;
            }

            public function getDisposition(): string
            {
                return 'attachment';
            }

            public function getId(): string
            {
                return '';
            }
        };
    }

    /**
     * @param WebklexAttachment|list<WebklexAttachment> $attachments
     */
    private function webklexMessage(WebklexAttachment|array $attachments): WebklexMessage
    {
        $attachments = is_array($attachments) ? $attachments : [$attachments];

        return new class ($attachments) extends WebklexMessage {
            /**
             * @var list<WebklexAttachment>
             */
            private array $mailikaAttachments;

            /**
             * @param list<WebklexAttachment> $attachments
             */
            public function __construct(array $attachments)
            {
                $this->mailikaAttachments = $attachments;
            }

            public function getAttachments(): AttachmentCollection
            {
                return new AttachmentCollection($this->mailikaAttachments);
            }
        };
    }
}

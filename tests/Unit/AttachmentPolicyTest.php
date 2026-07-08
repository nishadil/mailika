<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Config\Config;
use Mailika\Security\AttachmentPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AttachmentPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('c', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['MAILIKA_MAX_ATTACHMENT_BYTES'] = '10';
        $_ENV['MAILIKA_MAX_ATTACHMENTS'] = '2';
        $_ENV['MAILIKA_MAX_ATTACHMENT_TOTAL_BYTES'] = '15';
    }

    protected function tearDown(): void
    {
        unset(
            $_ENV['MAILIKA_MAX_ATTACHMENT_BYTES'],
            $_ENV['MAILIKA_MAX_ATTACHMENTS'],
            $_ENV['MAILIKA_MAX_ATTACHMENT_TOTAL_BYTES'],
        );
    }

    public function testSafeFilenameRemovesTraversalAndControlCharacters(): void
    {
        $policy = new AttachmentPolicy(Config::fromEnvironment(dirname(__DIR__, 2)));

        self::assertSame('passwd', $policy->safeFilename('../../etc/passwd'));
        self::assertSame('invoice_2026.pdf', $policy->safeFilename("invoice\n2026.pdf"));
        self::assertSame('attachment', $policy->safeFilename('..'));
    }

    public function testDownloadHeaderHelpersSanitizeFilenameAndContentType(): void
    {
        $policy = new AttachmentPolicy(Config::fromEnvironment(dirname(__DIR__, 2)));

        self::assertSame('application/octet-stream', $policy->safeContentType("text/html\r\nX-Test: 1"));
        self::assertSame(
            'attachment; filename="secret__Set-Cookie_ x_y.txt"',
            $policy->contentDisposition('attachment', "../../secret\r\nSet-Cookie: x=y.txt"),
        );
    }

    public function testInlineContentTypesAreRestrictedToSafeRasterImages(): void
    {
        $policy = new AttachmentPolicy(Config::fromEnvironment(dirname(__DIR__, 2)));

        self::assertTrue($policy->inlineContentTypeAllowed('image/png'));
        self::assertTrue($policy->inlineContentTypeAllowed('IMAGE/JPEG'));
        self::assertFalse($policy->inlineContentTypeAllowed('image/svg+xml'));
        self::assertFalse($policy->inlineContentTypeAllowed('text/html'));
        self::assertFalse($policy->inlineContentTypeAllowed("image/png\r\nX-Test: 1"));
    }

    public function testDownloadValidationRejectsReportedOrActualOversizedContent(): void
    {
        $policy = new AttachmentPolicy(Config::fromEnvironment(dirname(__DIR__, 2)));

        $policy->validateDownload(9, 9);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Attachment exceeds configured size limit.');

        $policy->validateDownload(4, 11);
    }

    public function testActiveAttachmentContentTypesAreDowngradedForDownload(): void
    {
        $policy = new AttachmentPolicy(Config::fromEnvironment(dirname(__DIR__, 2)));

        self::assertSame('image/png', $policy->downloadContentType('image/png', 'inline'));
        self::assertSame('text/plain', $policy->downloadContentType('text/plain', 'attachment'));
        self::assertSame('application/octet-stream', $policy->downloadContentType('text/html', 'attachment'));
        self::assertSame('application/octet-stream', $policy->downloadContentType('image/svg+xml', 'attachment'));
        self::assertSame(
            'application/octet-stream',
            $policy->downloadContentType('application/javascript', 'attachment'),
        );
    }

    public function testValidateUploadsSkipsEmptyUploadInputs(): void
    {
        $policy = new AttachmentPolicy(Config::fromEnvironment(dirname(__DIR__, 2)));

        self::assertSame([], $policy->validateUploads([
            ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
        ]));
    }

    public function testValidateUploadsRejectsTooManyAttachments(): void
    {
        $policy = new AttachmentPolicy(Config::fromEnvironment(dirname(__DIR__, 2)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Too many attachments');

        $policy->validateUploads([
            ['name' => 'a.txt', 'tmp_name' => '/tmp/a', 'error' => UPLOAD_ERR_OK, 'size' => 1],
            ['name' => 'b.txt', 'tmp_name' => '/tmp/b', 'error' => UPLOAD_ERR_OK, 'size' => 1],
            ['name' => 'c.txt', 'tmp_name' => '/tmp/c', 'error' => UPLOAD_ERR_OK, 'size' => 1],
        ]);
    }

    public function testValidateUploadsRejectsAggregateSizeBeforeFilesystemAccess(): void
    {
        $policy = new AttachmentPolicy(Config::fromEnvironment(dirname(__DIR__, 2)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('total size limit');

        $policy->validateUploads([
            ['name' => 'a.txt', 'tmp_name' => '/tmp/a', 'error' => UPLOAD_ERR_OK, 'size' => 8],
            ['name' => 'b.txt', 'tmp_name' => '/tmp/b', 'error' => UPLOAD_ERR_OK, 'size' => 8],
        ]);
    }

    public function testValidateUploadsRejectsFailedUpload(): void
    {
        $policy = new AttachmentPolicy(Config::fromEnvironment(dirname(__DIR__, 2)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Attachment upload failed.');

        $policy->validateUploads([
            ['name' => 'a.txt', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 1],
        ]);
    }
}

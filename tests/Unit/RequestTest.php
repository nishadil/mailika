<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testNormalizesMultipleUploadedFiles(): void
    {
        $request = new Request('POST', '/compose', [], [], [
            'attachments' => [
                'name' => ['a.txt', 'b.pdf'],
                'type' => ['text/plain', 'application/pdf'],
                'tmp_name' => ['/tmp/a', '/tmp/b'],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size' => [12, 34],
            ],
        ], [], []);

        $files = $request->uploadedFiles('attachments');

        self::assertCount(2, $files);
        self::assertSame('a.txt', $files[0]['name']);
        self::assertSame('/tmp/b', $files[1]['tmp_name']);
        self::assertSame(34, $files[1]['size']);
    }

    public function testNormalizesSingleUploadedFile(): void
    {
        $request = new Request('POST', '/compose', [], [], [
            'attachment' => [
                'name' => 'a.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/a',
                'error' => UPLOAD_ERR_OK,
                'size' => 12,
            ],
        ], [], []);

        $files = $request->uploadedFiles('attachment');

        self::assertCount(1, $files);
        self::assertSame('a.txt', $files[0]['name']);
    }

    public function testInputListNormalizesScalarArraysAndDuplicates(): void
    {
        $request = new Request('POST', '/message/action', [], [
            'selected_ids' => [' 1 ', '2', '1', '', ['nested']],
            'single' => ' 42 ',
        ], [], [], []);

        self::assertSame(['1', '2'], $request->inputList('selected_ids'));
        self::assertSame(['42'], $request->inputList('single'));
        self::assertSame([], $request->inputList('missing'));
    }

    public function testSecureRequestTrustsDirectHttpsWithoutProxyConfiguration(): void
    {
        $request = new Request('GET', '/', [], [], [], [], ['HTTPS' => 'on']);

        self::assertTrue($request->isSecure());
    }

    public function testSecureRequestDoesNotTrustForwardedProtoWithoutTrustedProxy(): void
    {
        $request = new Request('GET', '/', [], [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertFalse($request->isSecure());
    }

    public function testSecureRequestTrustsForwardedProtoFromExactTrustedProxy(): void
    {
        $request = new Request('GET', '/', [], [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertTrue($request->isSecure(['203.0.113.10']));
    }

    public function testSecureRequestTrustsForwardedProtoFromTrustedCidr(): void
    {
        $request = new Request('GET', '/', [], [], [], [], [
            'REMOTE_ADDR' => '10.12.3.4',
            'HTTP_X_FORWARDED_PROTO' => 'https,http',
        ]);

        self::assertTrue($request->isSecure(['10.0.0.0/8']));
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Config\Config;
use Mailika\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function testRemovesActiveContentAndRemoteImagesByDefault(): void
    {
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('b', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['MAILIKA_REMOTE_IMAGES'] = 'false';

        $sanitizer = new HtmlSanitizer(Config::fromEnvironment(dirname(__DIR__, 2)));
        $safe = $sanitizer->sanitize(
            '<p onclick="alert(1)">Hello</p><script>alert(1)</script><img src="https://tracker.example/pixel.png">',
        );

        self::assertStringContainsString('Hello', $safe);
        self::assertStringNotContainsString('onclick', $safe);
        self::assertStringNotContainsString('<script', $safe);
        self::assertStringNotContainsString('tracker.example', $safe);
    }

    public function testCanAllowRemoteImagesPerMessageRender(): void
    {
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('b', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['MAILIKA_REMOTE_IMAGES'] = 'false';

        $sanitizer = new HtmlSanitizer(Config::fromEnvironment(dirname(__DIR__, 2)));
        $safe = $sanitizer->sanitize('<img src="https://images.example/pixel.png">', true);

        self::assertStringContainsString('images.example', $safe);
    }

    public function testAllowsLocalAttachmentImagesWhileRemoteImagesAreBlocked(): void
    {
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('b', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['MAILIKA_REMOTE_IMAGES'] = 'false';

        $sanitizer = new HtmlSanitizer(Config::fromEnvironment(dirname(__DIR__, 2)));
        $safe = $sanitizer->sanitize(
            '<img src="/attachment?message=1&amp;attachment=logo&amp;inline=1">'
            . '<img src="https://tracker.example/pixel.png">',
        );

        self::assertStringContainsString('/attachment?message=1', $safe);
        self::assertStringContainsString('inline=1', $safe);
        self::assertStringNotContainsString('tracker.example', $safe);
    }

    public function testRemovesJavascriptLinksAndInlineStyles(): void
    {
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('b', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['MAILIKA_REMOTE_IMAGES'] = 'false';

        $sanitizer = new HtmlSanitizer(Config::fromEnvironment(dirname(__DIR__, 2)));
        $safe = $sanitizer->sanitize(
            '<a href="javascript:alert(1)" style="background:url(https://tracker.example/x)">Click</a>'
            . '<span style="color:red">Text</span>',
        );

        self::assertStringContainsString('<a>Click</a>', $safe);
        self::assertStringContainsString('<span>Text</span>', $safe);
        self::assertStringNotContainsString('javascript:', $safe);
        self::assertStringNotContainsString('style=', $safe);
        self::assertStringNotContainsString('tracker.example', $safe);
    }
}

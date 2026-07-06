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
}

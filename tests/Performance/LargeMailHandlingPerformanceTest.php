<?php

declare(strict_types=1);

namespace Mailika\Tests\Performance;

use Mailika\Config\Config;
use Mailika\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class LargeMailHandlingPerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('p', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['MAILIKA_REMOTE_IMAGES'] = 'false';
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_KEY'], $_ENV['MAILIKA_REMOTE_IMAGES']);
    }

    public function testLargeHtmlMessageSanitizationKeepsActiveContentOut(): void
    {
        $sanitizer = new HtmlSanitizer(Config::fromEnvironment(dirname(__DIR__, 2)));
        $chunk = '<section><p onclick="alert(1)">Quarterly update</p>'
            . '<img src="https://tracker.example/pixel.png" alt="tracker">'
            . '<script>alert("blocked")</script></section>';

        $safe = $sanitizer->sanitize(str_repeat($chunk, 600));

        self::assertStringContainsString('Quarterly update', $safe);
        self::assertStringNotContainsString('onclick', $safe);
        self::assertStringNotContainsString('<script', $safe);
        self::assertStringNotContainsString('tracker.example', $safe);
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Tests\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PublicPathSecurityTest extends TestCase
{
    public function testWritableStorageIsNotUnderPublicDocumentRoot(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertDirectoryDoesNotExist($root . '/public/storage');
        self::assertFileExists($root . '/public/.htaccess');
        self::assertFileExists($root . '/config/webserver/nginx-mailika.conf');
        self::assertFileExists($root . '/config/webserver/Caddyfile');
    }

    public function testPublicDocumentRootOnlyContainsFrontControllerAndBuiltAssets(): void
    {
        $root = dirname(__DIR__, 2);
        $public = $root . '/public';
        $allowed = [
            '.htaccess',
            'index.php',
        ];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($public) + 1));
            self::assertTrue(
                in_array($relative, $allowed, true) || str_starts_with($relative, 'build/'),
                'Unexpected public file: ' . $relative,
            );
            self::assertDoesNotMatchRegularExpression(
                '/(?:^|\/)(?:storage|vendor|src|templates|tests|database|config|docs|bin)\//',
                $relative,
            );
            self::assertDoesNotMatchRegularExpression(
                '/(?:\.env|\.log|composer\.(?:json|lock)|package-lock\.json)$/',
                $relative,
            );
        }
    }

    public function testApacheDenyRulesCoverSensitiveProjectPaths(): void
    {
        $rules = file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess') ?: '';

        foreach (['storage', 'vendor', 'src', 'templates', 'tests', 'database', 'config', 'docs', 'bin'] as $path) {
            self::assertStringContainsString($path, $rules);
        }

        self::assertStringContainsString('Options -Indexes', $rules);
        self::assertStringContainsString('composer\\.(json|lock)', $rules);
    }
}

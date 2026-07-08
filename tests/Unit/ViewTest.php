<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Preferences\LocaleCatalog;
use Mailika\Preferences\Preferences;
use Mailika\Support\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_URI']);
    }

    public function testLayoutUsesResolvedLocaleDirectionAndTheme(): void
    {
        $root = dirname(__DIR__, 2);
        $view = new View(
            $root . '/templates',
            $root . '/public',
            static fn (): Preferences => new Preferences('ar', 'UTC', false, 'dark'),
        );

        $html = $view->render('errors/500', ['title' => 'Error', 'exception' => null]);

        self::assertStringContainsString('lang="ar"', $html);
        self::assertStringContainsString('dir="rtl"', $html);
        self::assertStringContainsString('data-theme="dark"', $html);
        self::assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
    }

    public function testLayoutNormalizesStoredLocaleBeforeRendering(): void
    {
        $root = dirname(__DIR__, 2);
        $view = new View(
            $root . '/templates',
            $root . '/public',
            static fn (): Preferences => new Preferences('pt_br', 'UTC', false, 'system'),
        );

        $html = $view->render('errors/500', ['title' => 'Error', 'exception' => null]);

        self::assertStringContainsString('lang="pt-BR"', $html);
        self::assertStringContainsString('dir="ltr"', $html);
        self::assertStringContainsString('data-theme="system"', $html);
    }

    public function testLayoutRendersSkipTargetAndActiveNavigation(): void
    {
        $_SERVER['REQUEST_URI'] = '/settings';
        $root = dirname(__DIR__, 2);
        $view = new View($root . '/templates', $root . '/public');

        $html = $view->render('errors/500', ['title' => 'Error', 'exception' => null]);

        self::assertStringContainsString('<a class="skip-link" href="#main-content">Skip to content</a>', $html);
        self::assertStringContainsString('<main id="main-content" class="main" tabindex="-1">', $html);
        self::assertStringContainsString('<a href="/settings" aria-current="page">', $html);
    }

    public function testSettingsTemplateRendersSupportedLocaleSelect(): void
    {
        $root = dirname(__DIR__, 2);
        $view = new View($root . '/templates', $root . '/public');

        $html = $view->render('settings/index', [
            'layout' => false,
            'csrfToken' => 'token',
            'saved' => false,
            'preferences' => new Preferences('he-IL'),
            'locales' => LocaleCatalog::labels(),
            'timezones' => ['UTC'],
        ]);

        self::assertStringContainsString('<select name="locale">', $html);
        self::assertStringContainsString('<option value="he" selected>', $html);
        self::assertStringNotContainsString('name="locale" maxlength', $html);
    }
}

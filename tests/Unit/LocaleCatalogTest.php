<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Preferences\LocaleCatalog;
use PHPUnit\Framework\TestCase;

final class LocaleCatalogTest extends TestCase
{
    public function testNormalizesSupportedLocaleTags(): void
    {
        self::assertSame('en-US', LocaleCatalog::normalize('en_us'));
        self::assertSame('pt-BR', LocaleCatalog::normalize('pt-br'));
        self::assertSame('fa', LocaleCatalog::normalize('fa-IR'));
    }

    public function testFallsBackToEnglishForUnsupportedLocales(): void
    {
        self::assertSame('en', LocaleCatalog::normalize(''));
        self::assertSame('en', LocaleCatalog::normalize('zz-ZZ'));
    }

    public function testNegotiatesAcceptLanguageByQualityAndSupportedFallback(): void
    {
        self::assertSame('fa', LocaleCatalog::negotiateAcceptLanguage('fa-IR, en-US;q=0.8'));
        self::assertSame('pt-BR', LocaleCatalog::negotiateAcceptLanguage('zz-ZZ, pt-br;q=0.9, en;q=0.4'));
        self::assertSame('fr', LocaleCatalog::negotiateAcceptLanguage('fr-CA;q=0.6, de;q=0.4'));
    }

    public function testNegotiationIgnoresWildcardsUnsupportedLocalesAndZeroQuality(): void
    {
        self::assertSame('en', LocaleCatalog::negotiateAcceptLanguage('*, zz-ZZ;q=1, ar;q=0'));
        self::assertSame('en', LocaleCatalog::negotiateAcceptLanguage("ar\n;q=1, en;q=0.5"));
    }

    public function testReportsTextDirection(): void
    {
        self::assertSame('ltr', LocaleCatalog::direction('en-US'));
        self::assertSame('rtl', LocaleCatalog::direction('ar'));
        self::assertSame('rtl', LocaleCatalog::direction('he-IL'));
    }

    public function testLabelsExposeSupportedLocaleOptions(): void
    {
        $labels = LocaleCatalog::labels();

        self::assertSame('English', $labels['en']);
        self::assertSame('Arabic', $labels['ar']);
        self::assertArrayHasKey('pt-BR', $labels);
    }
}

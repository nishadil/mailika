<?php

declare(strict_types=1);

namespace Mailika\Preferences;

final class LocaleCatalog
{
    /**
     * @var array<string, array{label: string, direction: 'ltr'|'rtl'}>
     */
    private const LOCALES = [
        'en' => ['label' => 'English', 'direction' => 'ltr'],
        'en-US' => ['label' => 'English (United States)', 'direction' => 'ltr'],
        'en-GB' => ['label' => 'English (United Kingdom)', 'direction' => 'ltr'],
        'ar' => ['label' => 'Arabic', 'direction' => 'rtl'],
        'de' => ['label' => 'German', 'direction' => 'ltr'],
        'es' => ['label' => 'Spanish', 'direction' => 'ltr'],
        'fa' => ['label' => 'Persian', 'direction' => 'rtl'],
        'fr' => ['label' => 'French', 'direction' => 'ltr'],
        'he' => ['label' => 'Hebrew', 'direction' => 'rtl'],
        'hi' => ['label' => 'Hindi', 'direction' => 'ltr'],
        'pt-BR' => ['label' => 'Portuguese (Brazil)', 'direction' => 'ltr'],
        'ur' => ['label' => 'Urdu', 'direction' => 'rtl'],
    ];

    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::LOCALES as $locale => $metadata) {
            $labels[$locale] = $metadata['label'];
        }

        return $labels;
    }

    public static function normalize(string $locale): string
    {
        return self::matchSupportedLocale($locale) ?? 'en';
    }

    public static function negotiateAcceptLanguage(string $header): string
    {
        $candidates = [];
        $header = substr($header, 0, 512);

        foreach (explode(',', $header) as $order => $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (preg_match('/[\r\n]/', $part) === 1) {
                continue;
            }

            [$tag, $parameters] = array_pad(explode(';', $part, 2), 2, '');
            $tag = trim($tag);
            if (
                $tag === ''
                || $tag === '*'
                || preg_match('/^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{2,8})*$/', $tag) !== 1
            ) {
                continue;
            }

            $match = self::matchSupportedLocale($tag);
            if ($match === null) {
                continue;
            }

            $quality = self::quality($parameters);
            if ($quality <= 0.0) {
                continue;
            }

            $candidates[] = [
                'locale' => $match,
                'quality' => $quality,
                'order' => $order,
            ];
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => $right['quality'] <=> $left['quality']
                ?: $left['order'] <=> $right['order'],
        );

        return (string) ($candidates[0]['locale'] ?? 'en');
    }

    /**
     * @return 'ltr'|'rtl'
     */
    public static function direction(string $locale): string
    {
        $locale = self::normalize($locale);
        return self::LOCALES[$locale]['direction'];
    }

    private static function matchSupportedLocale(string $locale): ?string
    {
        $locale = trim(str_replace('_', '-', $locale));
        if ($locale === '') {
            return null;
        }

        $parts = explode('-', $locale);
        $language = strtolower($parts[0]);
        $region = strtoupper($parts[1] ?? '');
        $normalized = $region === '' ? $language : $language . '-' . $region;

        if (isset(self::LOCALES[$normalized])) {
            return $normalized;
        }

        return isset(self::LOCALES[$language]) ? $language : null;
    }

    private static function quality(string $parameters): float
    {
        foreach (explode(';', $parameters) as $parameter) {
            [$name, $value] = array_pad(explode('=', trim($parameter), 2), 2, null);
            if (strtolower((string) $name) !== 'q') {
                continue;
            }

            if ($value === null || !is_numeric($value)) {
                return 0.0;
            }

            return max(0.0, min(1.0, (float) $value));
        }

        return 1.0;
    }
}

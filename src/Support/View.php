<?php

declare(strict_types=1);

namespace Mailika\Support;

use Closure;
use Mailika\Preferences\Preferences;
use RuntimeException;
use Throwable;

final class View
{
    public function __construct(
        private readonly string $templateRoot,
        private readonly string $publicRoot,
        private readonly ?Closure $layoutPreferencesResolver = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $templatePath = $this->templateRoot . '/' . $template . '.php';

        if (!is_file($templatePath)) {
            throw new RuntimeException('Template not found: ' . $template);
        }

        extract($data, EXTR_SKIP);
        $view = $this;

        ob_start();
        require $templatePath;
        $content = (string) ob_get_clean();

        if (($data['layout'] ?? true) === false) {
            return $content;
        }

        ob_start();
        require $this->templateRoot . '/layout.php';
        return (string) ob_get_clean();
    }

    public function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function csrfInput(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->escape($token) . '">';
    }

    public function layoutPreferences(): Preferences
    {
        if ($this->layoutPreferencesResolver === null) {
            return new Preferences();
        }

        try {
            $preferences = ($this->layoutPreferencesResolver)();
        } catch (Throwable) {
            return new Preferences();
        }

        return $preferences instanceof Preferences ? $preferences : new Preferences();
    }

    public function asset(string $entry): string
    {
        $manifestPath = $this->publicRoot . '/build/.vite/manifest.json';

        if (is_file($manifestPath)) {
            try {
                $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($manifest) && isset($manifest[$entry]['file'])) {
                    return '/build/' . ltrim((string) $manifest[$entry]['file'], '/');
                }
            } catch (Throwable) {
                return '/' . ltrim($entry, '/');
            }
        }

        return '/' . ltrim($entry, '/');
    }
}

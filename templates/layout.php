<?php

/** @var Mailika\Support\View $view */
/** @var string $content */
/** @var string|null $title */

$pageTitle = isset($title) && is_string($title) && $title !== '' ? $title . ' - Mailika' : 'Mailika';
$layoutPreferences = $view->layoutPreferences();
$pageLocale = Mailika\Preferences\LocaleCatalog::normalize($layoutPreferences->locale);
$pageDirection = Mailika\Preferences\LocaleCatalog::direction($pageLocale);
$pageTheme = in_array($layoutPreferences->theme, ['system', 'light', 'dark'], true)
    ? $layoutPreferences->theme
    : 'system';
$currentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$currentPath = is_string($currentPath) && $currentPath !== '' ? $currentPath : '/';
$navigationItems = [
    ['label' => 'Mailbox', 'href' => '/mailbox', 'paths' => ['/mailbox', '/message']],
    ['label' => 'Compose', 'href' => '/compose', 'paths' => ['/compose']],
    ['label' => 'Contacts', 'href' => '/contacts', 'paths' => ['/contacts']],
    ['label' => 'Drafts', 'href' => '/drafts', 'paths' => ['/drafts']],
    ['label' => 'Filters', 'href' => '/filters', 'paths' => ['/filters']],
    ['label' => 'Settings', 'href' => '/settings', 'paths' => ['/settings', '/identities', '/accounts']],
];
?>
<!doctype html>
<html
  lang="<?= $view->escape($pageLocale) ?>"
  dir="<?= $view->escape($pageDirection) ?>"
  data-theme="<?= $view->escape($pageTheme) ?>"
>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title><?= $view->escape($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= $view->escape($view->asset('assets/css/app.css')) ?>">
  <script type="module" src="<?= $view->escape($view->asset('assets/ts/app.ts')) ?>" defer></script>
</head>
<body>
  <a class="skip-link" href="#main-content">Skip to content</a>
  <div class="shell">
    <header class="topbar">
      <a class="brand" href="/mailbox" aria-label="Mailika mailbox">
        <span class="brand-mark">M</span>
        <span>Mailika</span>
      </a>
      <nav class="topnav" aria-label="Primary">
        <?php foreach ($navigationItems as $item) : ?>
            <?php
            $active = false;
            foreach ($item['paths'] as $path) {
                if ($currentPath === $path || str_starts_with($currentPath, $path . '/')) {
                    $active = true;
                    break;
                }
            }
            ?>
          <a href="<?= $view->escape($item['href']) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
            <?= $view->escape($item['label']) ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </header>
    <main id="main-content" class="main" tabindex="-1">
      <?= $content ?>
    </main>
  </div>
</body>
</html>

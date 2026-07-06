<?php

/** @var Mailika\Support\View $view */
/** @var string $content */
/** @var string|null $title */

$pageTitle = isset($title) && is_string($title) && $title !== '' ? $title . ' - Mailika' : 'Mailika';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title><?= $view->escape($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= $view->escape($view->asset('assets/css/app.css')) ?>">
  <script type="module" src="<?= $view->escape($view->asset('assets/ts/app.ts')) ?>" defer></script>
</head>
<body>
  <div class="shell">
    <header class="topbar">
      <a class="brand" href="/mailbox" aria-label="Mailika mailbox">
        <span class="brand-mark">M</span>
        <span>Mailika</span>
      </a>
      <nav class="topnav" aria-label="Primary">
        <a href="/mailbox">Mailbox</a>
        <a href="/compose">Compose</a>
        <a href="/settings">Settings</a>
      </nav>
    </header>
    <main class="main">
      <?= $content ?>
    </main>
  </div>
</body>
</html>

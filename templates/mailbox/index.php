<?php

/** @var Mailika\Support\View $view */
/** @var string $csrfToken */
/** @var Mailika\Auth\MailboxCredentials $credentials */
/** @var string $folder */
/** @var list<Mailika\Mail\Folder> $folders */
/** @var list<Mailika\Mail\MessageSummary> $messages */
/** @var string|null $warning */
?>
<section class="workspace">
  <aside class="sidebar">
    <div class="identity">
      <span class="identity-label">Signed in</span>
      <strong><?= $view->escape($credentials->email) ?></strong>
    </div>
    <nav class="folder-list" aria-label="Folders">
      <?php if ($folders === []): ?>
        <a class="is-active" href="/mailbox?folder=INBOX">Inbox</a>
      <?php endif; ?>
      <?php foreach ($folders as $item): ?>
        <a class="<?= $item->name === $folder ? 'is-active' : '' ?>" href="/mailbox?folder=<?= urlencode($item->name) ?>">
          <span><?= $view->escape($item->displayName) ?></span>
          <?php if ($item->unread > 0): ?><span class="badge"><?= $item->unread ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <form method="post" action="/logout">
      <?= $view->csrfInput($csrfToken) ?>
      <button class="button button-secondary full-width" type="submit">Sign out</button>
    </form>
  </aside>

  <section class="content-pane">
    <div class="pane-header">
      <div>
        <p class="eyebrow">Folder</p>
        <h1><?= $view->escape($folder) ?></h1>
      </div>
      <a class="button button-primary" href="/compose">Compose</a>
    </div>

    <?php if ($warning !== null): ?>
      <div class="alert alert-warning" role="status"><?= $view->escape($warning) ?></div>
    <?php endif; ?>

    <div class="message-list">
      <?php if ($messages === []): ?>
        <div class="empty-state">
          <h2>No messages loaded</h2>
          <p>Connect a production mailbox with PHP IMAP enabled to load messages.</p>
        </div>
      <?php endif; ?>
      <?php foreach ($messages as $message): ?>
        <a class="message-row <?= $message->seen ? '' : 'is-unread' ?>" href="/message?folder=<?= urlencode($folder) ?>&id=<?= urlencode($message->id) ?>">
          <span class="message-from"><?= $view->escape($message->from) ?></span>
          <span class="message-subject"><?= $view->escape($message->subject) ?></span>
          <span class="message-date"><?= $view->escape($message->date) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
</section>

<?php

/** @var Mailika\Support\View $view */
/** @var string $csrfToken */
/** @var Mailika\Auth\MailboxCredentials $credentials */
/** @var string $folder */
/** @var list<Mailika\Mail\MailboxFolder> $folders */
/** @var Mailika\Mail\Message $message */
/** @var string $safeHtml */
$targetFolders = array_values(array_filter(
    $folders,
    static fn (Mailika\Mail\MailboxFolder $item): bool => $item->selectable && $item->name !== $folder,
));
?>
<section class="content-pane single-pane">
  <div class="pane-header">
    <div>
      <p class="eyebrow"><?= $view->escape($folder) ?></p>
      <h1><?= $view->escape($message->subject) ?></h1>
    </div>
    <div class="action-row">
      <a class="button button-secondary" href="/mailbox?folder=<?= urlencode($folder) ?>">Back</a>
      <a class="button button-secondary" href="/compose?mode=reply-all&folder=<?= urlencode($folder) ?>&id=<?= urlencode($message->id) ?>">Reply all</a>
      <a class="button button-secondary" href="/compose?mode=forward&folder=<?= urlencode($folder) ?>&id=<?= urlencode($message->id) ?>">Forward</a>
      <a class="button button-primary" href="/compose?mode=reply&folder=<?= urlencode($folder) ?>&id=<?= urlencode($message->id) ?>">Reply</a>
    </div>
  </div>

  <form method="post" action="/message/action" class="action-row message-actions">
    <?= $view->csrfInput($csrfToken) ?>
    <input type="hidden" name="folder" value="<?= $view->escape($folder) ?>">
    <input type="hidden" name="id" value="<?= $view->escape($message->id) ?>">
    <button class="button button-secondary" name="action" value="unseen" type="submit">Mark unread</button>
    <button class="button button-secondary" name="action" value="flag" type="submit">Flag</button>
    <button class="button button-secondary" name="action" value="archive" type="submit">Archive</button>
    <?php if ($targetFolders !== []): ?>
      <select name="target_folder" aria-label="Target folder">
        <?php foreach ($targetFolders as $target): ?>
          <option value="<?= $view->escape($target->name) ?>"><?= $view->escape($target->displayName) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="button button-secondary" name="action" value="move" type="submit">Move</button>
      <button class="button button-secondary" name="action" value="copy" type="submit">Copy</button>
    <?php endif; ?>
    <button class="button button-secondary" name="action" value="delete" type="submit">Delete</button>
  </form>

  <dl class="message-meta">
    <div><dt>From</dt><dd><?= $view->escape($message->from) ?></dd></div>
    <div><dt>To</dt><dd><?= $view->escape($message->to ?: $credentials->email) ?></dd></div>
    <div><dt>Date</dt><dd><?= $view->escape($message->date) ?></dd></div>
  </dl>

  <article class="mail-body" data-mail-body>
    <?= $safeHtml ?>
  </article>

  <?php if ($message->attachments !== []): ?>
    <section class="attachments">
      <h2>Attachments</h2>
      <?php foreach ($message->attachments as $attachment): ?>
        <div class="attachment">
          <?php if ($attachment->id !== null): ?>
            <a href="/attachment?folder=<?= urlencode($folder) ?>&message=<?= urlencode($message->id) ?>&attachment=<?= urlencode($attachment->id) ?>">
              <?= $view->escape($attachment->filename) ?>
            </a>
          <?php else: ?>
            <span><?= $view->escape($attachment->filename) ?></span>
          <?php endif; ?>
          <span><?= $view->escape($attachment->contentType) ?></span>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</section>

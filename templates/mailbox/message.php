<?php

/** @var Mailika\Support\View $view */
/** @var string $csrfToken */
/** @var Mailika\Auth\MailboxCredentials $credentials */
/** @var string $folder */
/** @var Mailika\Mail\Message $message */
/** @var string $safeHtml */
?>
<section class="content-pane single-pane">
  <div class="pane-header">
    <div>
      <p class="eyebrow"><?= $view->escape($folder) ?></p>
      <h1><?= $view->escape($message->subject) ?></h1>
    </div>
    <div class="action-row">
      <a class="button button-secondary" href="/mailbox?folder=<?= urlencode($folder) ?>">Back</a>
      <a class="button button-primary" href="/compose">Reply</a>
    </div>
  </div>

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
          <span><?= $view->escape($attachment->filename) ?></span>
          <span><?= $view->escape($attachment->contentType) ?></span>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</section>

<?php

/** @var Mailika\Support\View $view */
/** @var string $csrfToken */
/** @var string|null $error */
?>
<section class="content-pane single-pane">
  <div class="pane-header">
    <div>
      <p class="eyebrow">Message</p>
      <h1>Compose</h1>
    </div>
  </div>

  <?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert"><?= $view->escape($error) ?></div>
  <?php endif; ?>

  <form method="post" action="/compose" enctype="multipart/form-data" class="form-grid">
    <?= $view->csrfInput($csrfToken) ?>
    <label>
      <span>To</span>
      <input type="text" name="to" placeholder="name@example.com" required>
    </label>
    <label>
      <span>Subject</span>
      <input type="text" name="subject" maxlength="255">
    </label>
    <label>
      <span>Body</span>
      <textarea name="body" rows="14" required></textarea>
    </label>
    <label>
      <span>Attachment</span>
      <input type="file" name="attachment">
    </label>
    <div class="action-row">
      <a class="button button-secondary" href="/mailbox">Cancel</a>
      <button class="button button-primary" type="submit">Send</button>
    </div>
  </form>
</section>

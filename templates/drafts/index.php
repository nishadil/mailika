<?php

/** @var Mailika\Support\View $view */
/** @var string $csrfToken */
/** @var list<Mailika\Draft\DraftMessage> $drafts */
?>
<section class="content-pane single-pane">
  <div class="pane-header">
    <div>
      <p class="eyebrow">Saved locally</p>
      <h1>Drafts</h1>
    </div>
    <a class="button button-primary" href="/compose">Compose</a>
  </div>

  <div class="data-list">
    <?php if ($drafts === []): ?>
      <div class="empty-state">
        <h2>No drafts</h2>
        <p>Saved drafts appear here.</p>
      </div>
    <?php endif; ?>
    <?php foreach ($drafts as $draft): ?>
      <div class="data-row">
        <strong>
          <a href="/compose?draft=<?= urlencode($draft->id) ?>">
            <?= $view->escape($draft->subject ?: '(no subject)') ?>
          </a>
        </strong>
        <span><?= $view->escape($draft->to) ?></span>
        <small><?= $view->escape($draft->updatedAt) ?></small>
        <form method="post" action="/drafts/delete" class="inline-form">
          <?= $view->csrfInput($csrfToken) ?>
          <input type="hidden" name="id" value="<?= $view->escape($draft->id) ?>">
          <button class="button button-danger" type="submit">Delete</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</section>

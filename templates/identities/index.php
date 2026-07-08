<?php

/** @var Mailika\Support\View $view */
/** @var string $csrfToken */
/** @var list<Mailika\Identity\Identity> $identities */
/** @var string|null $error */
?>
<section class="content-pane single-pane">
  <div class="pane-header">
    <div>
      <p class="eyebrow">Sender profiles</p>
      <h1>Identities</h1>
    </div>
  </div>

  <?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert"><?= $view->escape($error) ?></div>
  <?php endif; ?>

  <form method="post" action="/identities" class="form-grid narrow-form">
    <?= $view->csrfInput($csrfToken) ?>
    <label>
      <span>Display name</span>
      <input type="text" name="display_name" maxlength="255">
    </label>
    <label>
      <span>Email</span>
      <input type="email" name="email" maxlength="320" required>
    </label>
    <label>
      <span>Reply-To</span>
      <input type="email" name="reply_to" maxlength="320">
    </label>
    <label class="check-row">
      <input type="checkbox" name="default" value="1">
      <span>Use as default sender</span>
    </label>
    <button class="button button-primary" type="submit">Add identity</button>
  </form>

  <div class="data-list">
    <?php foreach ($identities as $identity): ?>
      <div class="data-row">
        <strong><?= $view->escape($identity->displayName ?: $identity->email) ?></strong>
        <span><?= $view->escape($identity->email) ?><?= $identity->default ? ' default' : '' ?></span>
        <?php if ($identity->replyTo !== null): ?>
          <span>Reply-To <?= $view->escape($identity->replyTo) ?></span>
        <?php endif; ?>
        <?php if ($identity->id !== null): ?>
          <form method="post" action="/identities/delete" class="inline-form">
            <?= $view->csrfInput($csrfToken) ?>
            <input type="hidden" name="id" value="<?= (int) $identity->id ?>">
            <button class="button button-danger" type="submit">Delete</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

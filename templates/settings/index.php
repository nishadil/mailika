<?php

/** @var Mailika\Support\View $view */
/** @var string $csrfToken */
/** @var bool $saved */
/** @var bool $remoteImages */
?>
<section class="content-pane single-pane">
  <div class="pane-header">
    <div>
      <p class="eyebrow">Preferences</p>
      <h1>Settings</h1>
    </div>
  </div>

  <?php if ($saved): ?>
    <div class="alert alert-success" role="status">Settings saved.</div>
  <?php endif; ?>

  <form method="post" action="/settings" class="form-grid narrow-form">
    <?= $view->csrfInput($csrfToken) ?>
    <label class="check-row">
      <input type="checkbox" name="remote_images" value="1" <?= $remoteImages ? 'checked' : '' ?>>
      <span>Allow remote images for this session</span>
    </label>
    <button class="button button-primary" type="submit">Save settings</button>
  </form>
</section>

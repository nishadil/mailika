<?php

/** @var Mailika\Support\View $view */
/** @var string $csrfToken */
/** @var string|null $error */
/** @var array<string, mixed> $defaults */
?>
<section class="auth-screen">
  <div class="auth-panel">
    <div class="section-heading">
      <p class="eyebrow">Self-hosted webmail</p>
      <h1>Sign in to your mailbox</h1>
    </div>

    <?php if ($error !== null): ?>
      <div class="alert alert-error" role="alert"><?= $view->escape($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login" class="form-grid" data-secure-form>
      <?= $view->csrfInput($csrfToken) ?>

      <label>
        <span>Email address</span>
        <input type="email" name="email" autocomplete="username" required maxlength="320">
      </label>

      <label>
        <span>Password</span>
        <input type="password" name="password" autocomplete="current-password" required>
      </label>

      <div class="field-row">
        <label>
          <span>IMAP host</span>
          <input type="text" name="imap_host" value="<?= $view->escape($defaults['imap_host'] ?? '') ?>" required>
        </label>
        <label>
          <span>Port</span>
          <input type="number" name="imap_port" min="1" max="65535" value="<?= $view->escape($defaults['imap_port'] ?? 993) ?>" required>
        </label>
      </div>

      <label class="check-row">
        <input type="checkbox" name="imap_tls" value="1" <?= ($defaults['imap_tls'] ?? true) ? 'checked' : '' ?>>
        <span>Use TLS for IMAP</span>
      </label>

      <div class="field-row">
        <label>
          <span>SMTP host</span>
          <input type="text" name="smtp_host" value="<?= $view->escape($defaults['smtp_host'] ?? '') ?>" required>
        </label>
        <label>
          <span>Port</span>
          <input type="number" name="smtp_port" min="1" max="65535" value="<?= $view->escape($defaults['smtp_port'] ?? 587) ?>" required>
        </label>
      </div>

      <label>
        <span>SMTP security</span>
        <select name="smtp_tls">
          <?php foreach (['starttls' => 'STARTTLS', 'smtps' => 'SMTPS', 'none' => 'None'] as $value => $label): ?>
            <option value="<?= $view->escape($value) ?>" <?= ($defaults['smtp_tls'] ?? 'starttls') === $value ? 'selected' : '' ?>>
              <?= $view->escape($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <button class="button button-primary" type="submit">Sign in</button>
    </form>
  </div>
</section>

<?php

/** @var Mailika\Support\View $view */
/** @var string $csrfToken */
/** @var string|null $error */
/** @var list<Mailika\Mail\MailAccountProfile> $profiles */
/** @var Mailika\Mail\MailAccountProfile|null $currentProfile */
?>
<section class="content-pane single-pane">
  <div class="pane-header">
    <div>
      <p class="eyebrow">Non-secret profiles</p>
      <h1>Account profiles</h1>
    </div>
  </div>

  <?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert"><?= $view->escape($error) ?></div>
  <?php endif; ?>

  <?php if ($currentProfile !== null): ?>
    <form method="post" action="/accounts" class="compact-form">
      <?= $view->csrfInput($csrfToken) ?>
      <input type="hidden" name="label" value="<?= $view->escape($currentProfile->label) ?>">
      <input type="hidden" name="email" value="<?= $view->escape($currentProfile->email) ?>">
      <input type="hidden" name="imap_host" value="<?= $view->escape($currentProfile->imapHost) ?>">
      <input type="hidden" name="imap_port" value="<?= $currentProfile->imapPort ?>">
      <input type="hidden" name="imap_tls" value="<?= $currentProfile->imapTls ? '1' : '0' ?>">
      <input type="hidden" name="smtp_host" value="<?= $view->escape($currentProfile->smtpHost) ?>">
      <input type="hidden" name="smtp_port" value="<?= $currentProfile->smtpPort ?>">
      <input type="hidden" name="smtp_tls" value="<?= $view->escape($currentProfile->smtpTls) ?>">
      <button class="button button-primary" type="submit">Save current account profile</button>
    </form>
  <?php endif; ?>

  <form method="post" action="/accounts" class="form-grid">
    <?= $view->csrfInput($csrfToken) ?>
    <div class="field-row">
      <label>
        <span>Label</span>
        <input type="text" name="label" maxlength="128">
      </label>
      <label>
        <span>Email address</span>
        <input type="email" name="email" maxlength="320" required>
      </label>
    </div>
    <div class="field-row">
      <label>
        <span>IMAP host</span>
        <input type="text" name="imap_host" required>
      </label>
      <label>
        <span>Port</span>
        <input type="number" name="imap_port" min="1" max="65535" value="993" required>
      </label>
    </div>
    <label class="check-row">
      <input type="checkbox" name="imap_tls" value="1" checked>
      <span>Use TLS for IMAP</span>
    </label>
    <div class="field-row">
      <label>
        <span>SMTP host</span>
        <input type="text" name="smtp_host" required>
      </label>
      <label>
        <span>Port</span>
        <input type="number" name="smtp_port" min="1" max="65535" value="587" required>
      </label>
    </div>
    <label>
      <span>SMTP security</span>
      <select name="smtp_tls">
        <?php foreach (['starttls' => 'STARTTLS', 'smtps' => 'SMTPS', 'none' => 'None'] as $value => $label): ?>
          <option value="<?= $view->escape($value) ?>"><?= $view->escape($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="button button-secondary" type="submit">Add profile</button>
  </form>

  <div class="data-list">
    <?php if ($profiles === []): ?>
      <div class="empty-state">
        <h2>No saved profiles</h2>
      </div>
    <?php endif; ?>
    <?php foreach ($profiles as $profile): ?>
      <div class="data-row">
        <strong><?= $view->escape($profile->label) ?></strong>
        <span><?= $view->escape($profile->email) ?></span>
        <small>
          IMAP <?= $view->escape($profile->imapHost) ?>:<?= $profile->imapPort ?>
          <?= $profile->imapTls ? 'TLS' : 'No TLS' ?>
        </small>
        <small>
          SMTP <?= $view->escape($profile->smtpHost) ?>:<?= $profile->smtpPort ?>
          <?= $view->escape(strtoupper($profile->smtpTls)) ?>
        </small>
        <?php if ($profile->id !== null): ?>
          <form method="post" action="/accounts/select" class="inline-form">
            <?= $view->csrfInput($csrfToken) ?>
            <input type="hidden" name="id" value="<?= (int) $profile->id ?>">
            <button class="button button-secondary" type="submit">Use for sign in</button>
          </form>
          <form method="post" action="/accounts/delete" class="inline-form">
            <?= $view->csrfInput($csrfToken) ?>
            <input type="hidden" name="id" value="<?= (int) $profile->id ?>">
            <button class="button button-danger" type="submit">Delete</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

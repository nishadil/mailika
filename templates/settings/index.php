<?php

/** @var Mailika\Support\View $view */
/** @var string $csrfToken */
/** @var bool $saved */
/** @var Mailika\Preferences\Preferences $preferences */
/** @var array<string, string> $locales */
/** @var list<string> $timezones */
$selectedLocale = Mailika\Preferences\LocaleCatalog::normalize($preferences->locale);
?>
<section class="content-pane single-pane">
  <div class="pane-header">
    <div>
      <p class="eyebrow">Preferences</p>
      <h1>Settings</h1>
    </div>
  </div>

  <?php if ($saved) : ?>
    <div class="alert alert-success" role="status">Settings saved.</div>
  <?php endif; ?>

  <form method="post" action="/settings" class="form-grid narrow-form">
    <?= $view->csrfInput($csrfToken) ?>
    <label>
      <span>Locale</span>
      <select name="locale">
        <?php foreach ($locales as $locale => $label) : ?>
          <option value="<?= $view->escape($locale) ?>" <?= $selectedLocale === $locale ? 'selected' : '' ?>>
            <?= $view->escape($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <span>Timezone</span>
      <select name="timezone">
        <?php foreach ($timezones as $timezone) : ?>
          <option value="<?= $view->escape($timezone) ?>" <?= $preferences->timezone === $timezone ? 'selected' : '' ?>>
            <?= $view->escape($timezone) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <span>Theme</span>
      <select name="theme">
        <?php foreach (['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label) : ?>
          <option value="<?= $view->escape($value) ?>" <?= $preferences->theme === $value ? 'selected' : '' ?>>
            <?= $view->escape($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <span>Messages per page</span>
      <select name="messages_per_page">
        <?php foreach ([25, 50, 100] as $value) : ?>
          <option value="<?= $value ?>" <?= $preferences->messagesPerPage === $value ? 'selected' : '' ?>>
            <?= $value ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="check-row">
      <input type="checkbox" name="remote_images" value="1" <?= $preferences->remoteImages ? 'checked' : '' ?>>
      <span>Allow remote images for this mailbox</span>
    </label>
    <label class="check-row">
      <input type="checkbox" name="threaded_listing" value="1" <?= $preferences->threadedListing ? 'checked' : '' ?>>
      <span>Group related messages in mailbox lists</span>
    </label>
    <div class="action-row">
      <a class="button button-secondary" href="/identities">Manage identities</a>
      <a class="button button-secondary" href="/contacts">Manage contacts</a>
      <a class="button button-secondary" href="/filters">Manage filters</a>
      <a class="button button-secondary" href="/accounts">Manage account profiles</a>
    </div>
    <button class="button button-primary" type="submit">Save settings</button>
  </form>
</section>

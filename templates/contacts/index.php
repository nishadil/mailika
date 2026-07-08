<?php

/** @var Mailika\Support\View $view */
/** @var string $csrfToken */
/** @var list<Mailika\Contact\Contact> $contacts */
/** @var list<Mailika\Contact\ContactGroup> $groups */
/** @var string|null $error */
/** @var bool $directoryConfigured */
/** @var string $directoryName */
/** @var string $directoryQuery */
/** @var list<Mailika\Contact\Contact> $directoryResults */
?>
<section class="content-pane single-pane">
  <div class="pane-header">
    <div>
      <p class="eyebrow">Address book</p>
      <h1>Contacts</h1>
    </div>
    <a class="button button-secondary" href="/contacts/export">Export vCard</a>
  </div>

  <?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert"><?= $view->escape($error) ?></div>
  <?php endif; ?>

  <div class="split-layout">
    <form method="post" action="/contacts" class="form-grid narrow-form">
      <?= $view->csrfInput($csrfToken) ?>
      <label>
        <span>Name</span>
        <input type="text" name="display_name" maxlength="255" required>
      </label>
      <label>
        <span>Email</span>
        <input type="email" name="email" maxlength="320" required>
      </label>
      <label>
        <span>Group</span>
        <select name="group_id">
          <option value="0">No group</option>
          <?php foreach ($groups as $group): ?>
            <option value="<?= (int) $group->id ?>"><?= $view->escape($group->name) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <span>Notes</span>
        <textarea name="notes" rows="3"></textarea>
      </label>
      <button class="button button-primary" type="submit">Add contact</button>
    </form>

    <div class="form-stack">
      <form method="post" action="/contacts/groups" class="form-grid">
        <?= $view->csrfInput($csrfToken) ?>
        <label>
          <span>New group</span>
          <input type="text" name="name" maxlength="255" required>
        </label>
        <button class="button button-secondary" type="submit">Create group</button>
      </form>

      <?php if ($groups !== []): ?>
        <div class="data-list compact-list">
          <?php foreach ($groups as $group): ?>
            <div class="data-row">
              <strong><?= $view->escape($group->name) ?></strong>
              <form method="post" action="/contacts/groups/delete" class="inline-form">
                <?= $view->csrfInput($csrfToken) ?>
                <input type="hidden" name="group_id" value="<?= (int) $group->id ?>">
                <button class="button button-danger" type="submit">Delete group</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($directoryConfigured): ?>
        <form method="get" action="/contacts" class="form-grid">
          <label>
            <span><?= $view->escape($directoryName) ?></span>
            <input
              type="search"
              name="directory_q"
              minlength="2"
              maxlength="128"
              value="<?= $view->escape($directoryQuery) ?>"
            >
          </label>
          <button class="button button-secondary" type="submit">Search directory</button>
        </form>

        <?php if ($directoryResults !== []): ?>
          <div class="data-list compact-list">
            <?php foreach ($directoryResults as $directoryContact): ?>
              <div class="data-row">
                <strong><?= $view->escape($directoryContact->displayName) ?></strong>
                <span><?= $view->escape($directoryContact->email) ?></span>
                <form method="post" action="/contacts" class="inline-form">
                  <?= $view->csrfInput($csrfToken) ?>
                  <input type="hidden" name="display_name" value="<?= $view->escape($directoryContact->displayName) ?>">
                  <input type="hidden" name="email" value="<?= $view->escape($directoryContact->email) ?>">
                  <input type="hidden" name="notes" value="<?= $view->escape((string) $directoryContact->notes) ?>">
                  <input type="hidden" name="group_id" value="0">
                  <button class="button button-secondary" type="submit">Add contact</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <form method="post" action="/contacts/import" enctype="multipart/form-data" class="form-grid">
        <?= $view->csrfInput($csrfToken) ?>
        <label>
          <span>Import into group</span>
          <select name="group_id">
            <option value="0">No group</option>
            <?php foreach ($groups as $group): ?>
              <option value="<?= (int) $group->id ?>"><?= $view->escape($group->name) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span>vCard file</span>
          <input type="file" name="vcard" accept=".vcf,text/vcard,text/x-vcard">
        </label>
        <label>
          <span>vCard text</span>
          <textarea name="vcard_text" rows="4"></textarea>
        </label>
        <button class="button button-secondary" type="submit">Import vCard</button>
      </form>
    </div>
  </div>

  <div class="data-list">
    <?php if ($contacts === []): ?>
      <div class="empty-state">
        <h2>No contacts yet</h2>
        <p>Saved contacts appear here and can be selected while composing mail.</p>
      </div>
    <?php endif; ?>
    <?php foreach ($contacts as $contact): ?>
      <div class="data-row">
        <strong><?= $view->escape($contact->displayName) ?></strong>
        <span><?= $view->escape($contact->email) ?></span>
        <?php if ($contact->groups !== []): ?>
          <span class="tag-list">
            <?php foreach ($contact->groups as $group): ?>
              <span class="tag"><?= $view->escape($group) ?></span>
            <?php endforeach; ?>
          </span>
        <?php endif; ?>
        <form method="post" action="/contacts/delete" class="inline-form">
          <?= $view->csrfInput($csrfToken) ?>
          <input type="hidden" name="email" value="<?= $view->escape($contact->email) ?>">
          <button class="button button-danger" type="submit">Delete</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</section>

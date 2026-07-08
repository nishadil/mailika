<?php

/** @var Mailika\Support\View $view */
/** @var string $csrfToken */
/** @var string|null $error */
/** @var list<Mailika\Identity\Identity> $identities */
/** @var list<Mailika\Contact\Contact> $contacts */
/** @var array{to:string,cc:string,bcc:string,subject:string,body:string,identity:string,reply_to_message_id:string,draft_id:string} $defaults */
$contactSuggestions = [];
$contactEmails = [];
foreach ($contacts as $contact) {
    if (in_array($contact->email, $contactEmails, true)) {
        continue;
    }

    $contactSuggestions[] = [
        'email' => $contact->email,
        'label' => $contact->displayName !== '' ? $contact->displayName : $contact->email,
        'search' => trim($contact->displayName . ' ' . $contact->email),
    ];
    $contactEmails[] = $contact->email;
}
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
    <input type="hidden" name="reply_to_message_id" value="<?= $view->escape($defaults['reply_to_message_id']) ?>">
    <input type="hidden" name="draft_id" value="<?= $view->escape($defaults['draft_id']) ?>">
    <label>
      <span>From</span>
      <select name="identity">
        <?php foreach ($identities as $identity): ?>
          <option value="<?= $view->escape($identity->email) ?>" <?= $defaults['identity'] === $identity->email ? 'selected' : '' ?>>
            <?= $view->escape($identity->displayName !== '' ? $identity->displayName . ' <' . $identity->email . '>' : $identity->email) ?>
            <?= $identity->replyTo !== null ? $view->escape(' Reply-To ' . $identity->replyTo) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <span>To</span>
      <input
        type="text"
        name="to"
        placeholder="name@example.com, team@example.com"
        value="<?= $view->escape($defaults['to']) ?>"
        data-recipient-input
        required
      >
    </label>
    <div class="field-row">
      <label>
        <span>CC</span>
        <input type="text" name="cc" value="<?= $view->escape($defaults['cc']) ?>" data-recipient-input>
      </label>
      <label>
        <span>BCC</span>
        <input type="text" name="bcc" value="<?= $view->escape($defaults['bcc']) ?>" data-recipient-input>
      </label>
    </div>
    <?php if ($contactSuggestions !== []): ?>
      <div class="recipient-suggestions" data-recipient-suggestions aria-label="Contacts">
        <?php foreach ($contactSuggestions as $contact): ?>
          <button
            type="button"
            data-recipient-suggestion
            data-email="<?= $view->escape($contact['email']) ?>"
            data-search="<?= $view->escape($contact['search']) ?>"
          >
            <span><?= $view->escape($contact['label']) ?></span>
            <small><?= $view->escape($contact['email']) ?></small>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <label>
      <span>Subject</span>
      <input type="text" name="subject" maxlength="255" value="<?= $view->escape($defaults['subject']) ?>">
    </label>
    <label>
      <span>Body</span>
      <textarea name="body" rows="14" required><?= $view->escape($defaults['body']) ?></textarea>
    </label>
    <label>
      <span>Attachments</span>
      <input type="file" name="attachments[]" multiple>
    </label>
    <div class="action-row">
      <a class="button button-secondary" href="/mailbox">Cancel</a>
      <button class="button button-secondary" name="intent" value="draft" type="submit">Save draft</button>
      <button class="button button-primary" type="submit">Send</button>
    </div>
  </form>
</section>

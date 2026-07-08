<?php

/** @var Mailika\Support\View $view */
/** @var string $csrfToken */
/** @var Mailika\Auth\MailboxCredentials $credentials */
/** @var string $folder */
/** @var string $query */
/** @var string $canonicalSearchQuery */
/** @var Mailika\Mail\MessageSearchCriteria $searchCriteria */
/** @var int $page */
/** @var list<Mailika\Mail\MailboxFolder> $folders */
/** @var list<Mailika\Mail\MessageEnvelope> $messages */
/** @var string $mailboxStatus */
/** @var Mailika\Mail\MailboxQuota $quota */
/** @var Mailika\Mail\MailboxCapabilities $capabilities */
/** @var Mailika\Mail\MailboxRights $mailboxRights */
/** @var list<Mailika\Mail\MailboxAclEntry> $mailboxAcl */
/** @var Mailika\Preferences\Preferences $preferences */
/** @var list<Mailika\Mail\SavedSearch> $savedSearches */
/** @var list<Mailika\Mail\MailAccountProfile> $accountProfiles */
/** @var string|null $warning */
$targetFolders = array_values(array_filter(
    $folders,
    static fn (Mailika\Mail\MailboxFolder $item): bool => $item->selectable && $item->name !== $folder,
));
$activeFolder = null;
foreach ($folders as $item) {
    if ($item->name === $folder) {
        $activeFolder = $item;
        break;
    }
}
$rightsAvailable = $mailboxRights->available();
$canChangeSeen = !$rightsAvailable || $mailboxRights->canKeepSeen();
$canWriteFlags = !$rightsAvailable || $mailboxRights->canWriteFlags();
$canCopyMessages = !$rightsAvailable || $mailboxRights->canRead();
$canDeleteMessages = !$rightsAvailable || $mailboxRights->canDeleteMessages();
$canMoveMessages = $canDeleteMessages;
$canCreateFolders = !$rightsAvailable || $mailboxRights->canCreateMailbox();
$canManageFolderRights = !$rightsAvailable || $mailboxRights->canDeleteMailbox();
$canAdministerAcl = $rightsAvailable && $mailboxRights->canAdminister();
$messageActionButtonsAvailable = $canChangeSeen
    || $canWriteFlags
    || $canDeleteMessages
    || ($targetFolders !== [] && ($canMoveMessages || $canCopyMessages));
$folderManageable = $activeFolder !== null
    && $activeFolder->selectable
    && $activeFolder->specialUse === []
    && strcasecmp($activeFolder->name, 'INBOX') !== 0
    && $canManageFolderRights;
$quotaPercent = $quota->usedBytes !== null && $quota->limitBytes !== null && $quota->limitBytes > 0
    ? (int) round(($quota->usedBytes / $quota->limitBytes) * 100)
    : null;
$capabilityLabels = array_filter([
    $capabilities->move ? 'MOVE' : null,
    $capabilities->quota ? 'QUOTA' : null,
    $capabilities->acl ? 'ACL' : null,
    $capabilities->idle ? 'IDLE' : null,
    $capabilities->sort ? 'SORT' : null,
    $capabilities->thread ? 'THREAD' : null,
]);
$rightsLabel = static fn (Mailika\Mail\MailboxRights $rights): array => array_filter([
    $rights->canLookup() ? 'Lookup' : null,
    $rights->canRead() ? 'Read' : null,
    $rights->canKeepSeen() ? 'Seen' : null,
    $rights->canWriteFlags() ? 'Flags' : null,
    $rights->canInsert() ? 'Insert' : null,
    $rights->canPost() ? 'Post' : null,
    $rights->canCreateMailbox() ? 'Create folder' : null,
    $rights->canDeleteMailbox() ? 'Delete folder' : null,
    $rights->canDeleteMessages() ? 'Delete mail' : null,
    $rights->canExpunge() ? 'Expunge' : null,
    $rights->canAdminister() ? 'Admin' : null,
]);
$rightLabels = $mailboxRights->available() ? $rightsLabel($mailboxRights) : [];
$aclRightOptions = [
    'l' => 'Lookup',
    'r' => 'Read',
    's' => 'Seen',
    'w' => 'Flags',
    'i' => 'Insert',
    'p' => 'Post',
    'k' => 'Create folder',
    'x' => 'Delete folder',
    't' => 'Delete mail',
    'e' => 'Expunge',
    'a' => 'Admin',
];
$statusLabel = match ($mailboxStatus) {
    'cached' => 'Cached',
    'unavailable' => 'Unavailable',
    default => 'Live',
};
$statusClass = match ($mailboxStatus) {
    'cached' => 'is-warning',
    'unavailable' => 'is-error',
    default => 'is-ok',
};
$messageRows = [];
$threads = [];

foreach ($messages as $message) {
    $threadKey = $message->threadId ?: 'message:' . $message->id;
    if (!isset($threads[$threadKey])) {
        $threads[$threadKey] = [];
    }

    $threads[$threadKey][] = $message;
}

$threadGroups = $preferences->threadedListing
    ? $threads
    : array_map(static fn (Mailika\Mail\MessageEnvelope $message): array => [$message], $messages);

foreach ($threadGroups as $thread) {
    foreach ($thread as $position => $message) {
        $messageRows[] = [
            'message' => $message,
            'thread_position' => $position,
            'thread_count' => count($thread),
        ];
    }
}

$nextPageUrl = '/mailbox?' . http_build_query([
    'folder' => $folder,
    'q' => $canonicalSearchQuery,
    'page' => $page + 1,
]);
$previousPageUrl = '/mailbox?' . http_build_query([
    'folder' => $folder,
    'q' => $canonicalSearchQuery,
    'page' => $page - 1,
]);
$advancedSearchOpen = $searchCriteria->from !== null
    || $searchCriteria->to !== null
    || $searchCriteria->subject !== null
    || $searchCriteria->unseenOnly
    || $searchCriteria->flaggedOnly;
?>
<section class="workspace">
  <aside class="sidebar">
    <div class="identity">
      <span class="identity-label">Signed in</span>
      <strong><?= $view->escape($credentials->email) ?></strong>
    </div>
    <?php if ($accountProfiles !== []) : ?>
      <div class="sidebar-section">
        <p class="identity-label">Accounts</p>
        <div class="profile-switcher">
          <?php foreach ($accountProfiles as $profile) : ?>
                <?php $currentProfile = strcasecmp($profile->email, $credentials->email) === 0; ?>
            <div class="profile-row <?= $currentProfile ? 'is-active' : '' ?>">
              <div class="profile-summary">
                <strong><?= $view->escape($profile->label) ?></strong>
                <span><?= $view->escape($profile->email) ?></span>
              </div>
                <?php if (!$currentProfile && $profile->id !== null) : ?>
                <form method="post" action="/accounts/select" class="profile-switch-form">
                    <?= $view->csrfInput($csrfToken) ?>
                  <input type="hidden" name="id" value="<?= (int) $profile->id ?>">
                  <button class="button button-secondary" type="submit">Use</button>
                </form>
                <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
    <nav class="folder-list" aria-label="Folders">
      <?php if ($folders === []) : ?>
        <a class="is-active" href="/mailbox?folder=INBOX">Inbox</a>
      <?php endif; ?>
      <?php foreach ($folders as $item) : ?>
        <a
          class="<?= $item->name === $folder ? 'is-active' : '' ?>"
          href="/mailbox?folder=<?= urlencode($item->name) ?>"
            <?php if ($item->selectable && $item->name !== $folder) : ?>
            data-folder-drop-target
            data-target-folder="<?= $view->escape($item->name) ?>"
            <?php endif; ?>
        >
          <span><?= $view->escape($item->displayName) ?></span>
            <?php if ($item->unread > 0) :
                ?><span class="badge"><?= $item->unread ?></span><?php
            endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php if ($savedSearches !== []) : ?>
      <div class="sidebar-section">
        <p class="identity-label">Saved searches</p>
        <nav class="folder-list" aria-label="Saved searches">
          <?php foreach ($savedSearches as $search) : ?>
            <div class="saved-search-row">
              <a href="/mailbox?folder=<?= urlencode($search->folder) ?>&q=<?= urlencode($search->query) ?>">
                <?= $view->escape($search->name) ?>
              </a>
                <?php if ($search->id !== null) : ?>
                <form method="post" action="/searches/delete" class="saved-search-delete">
                    <?= $view->csrfInput($csrfToken) ?>
                  <input type="hidden" name="id" value="<?= (int) $search->id ?>">
                  <input type="hidden" name="folder" value="<?= $view->escape($folder) ?>">
                  <input type="hidden" name="q" value="<?= $view->escape($canonicalSearchQuery) ?>">
                  <button class="button button-danger" type="submit">Delete</button>
                </form>
                <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </nav>
      </div>
    <?php endif; ?>
    <?php if ($mailboxAcl !== [] || $canAdministerAcl) : ?>
      <div class="sidebar-section">
        <p class="identity-label">Folder access</p>
        <?php if ($mailboxAcl !== []) : ?>
          <div class="acl-list">
            <?php foreach ($mailboxAcl as $entry) : ?>
                <?php $entryRights = $rightsLabel($entry->rights); ?>
              <div class="acl-row">
                <strong><?= $view->escape($entry->identifier) ?></strong>
                <span title="IMAP rights <?= $view->escape($entry->rights->raw) ?>">
                  <?= $view->escape(implode(', ', $entryRights) ?: 'No rights') ?>
                </span>
                <?php if ($canAdministerAcl) : ?>
                  <form method="post" action="/folders/acl/delete" class="acl-delete-form">
                    <?= $view->csrfInput($csrfToken) ?>
                    <input type="hidden" name="folder" value="<?= $view->escape($folder) ?>">
                    <input type="hidden" name="identifier" value="<?= $view->escape($entry->identifier) ?>">
                    <button class="button button-danger" type="submit">Revoke</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($canAdministerAcl) : ?>
          <form method="post" action="/folders/acl" class="compact-form acl-editor">
            <?= $view->csrfInput($csrfToken) ?>
            <input type="hidden" name="folder" value="<?= $view->escape($folder) ?>">
            <label>
              <span>Identifier</span>
              <input type="text" name="identifier" maxlength="255" autocomplete="off">
            </label>
            <div class="acl-rights-grid" aria-label="Rights">
              <?php foreach ($aclRightOptions as $right => $label) : ?>
                <label class="check-row">
                  <input type="checkbox" name="rights[]" value="<?= $view->escape($right) ?>">
                  <span><?= $view->escape($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <button class="button button-secondary full-width" type="submit">Save access</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($canCreateFolders) : ?>
      <form method="post" action="/folders/create" class="compact-form">
        <?= $view->csrfInput($csrfToken) ?>
        <label>
          <span>New folder</span>
          <input type="text" name="name" placeholder="Archive/2026">
        </label>
        <button class="button button-secondary full-width" type="submit">Create</button>
      </form>
    <?php endif; ?>

    <?php if ($folderManageable) : ?>
      <form method="post" action="/folders/rename" class="compact-form">
        <?= $view->csrfInput($csrfToken) ?>
        <input type="hidden" name="folder" value="<?= $view->escape($folder) ?>">
        <label>
          <span>Rename folder</span>
          <input type="text" name="new_name" maxlength="512" value="<?= $view->escape($folder) ?>">
        </label>
        <button class="button button-secondary full-width" type="submit">Rename</button>
      </form>

      <form method="post" action="/folders/delete" class="compact-form">
        <?= $view->csrfInput($csrfToken) ?>
        <input type="hidden" name="folder" value="<?= $view->escape($folder) ?>">
        <button class="button button-danger full-width" type="submit">Delete folder</button>
      </form>
    <?php endif; ?>

    <?php if ($canonicalSearchQuery !== '') : ?>
      <form method="post" action="/searches" class="compact-form">
        <?= $view->csrfInput($csrfToken) ?>
        <input type="hidden" name="folder" value="<?= $view->escape($folder) ?>">
        <input type="hidden" name="q" value="<?= $view->escape($canonicalSearchQuery) ?>">
        <label>
          <span>Save search</span>
          <input type="text" name="name" maxlength="255" value="<?= $view->escape($canonicalSearchQuery) ?>">
        </label>
        <button class="button button-secondary full-width" type="submit">Save</button>
      </form>
    <?php endif; ?>

    <form method="post" action="/logout">
      <?= $view->csrfInput($csrfToken) ?>
      <button class="button button-secondary full-width" type="submit">Sign out</button>
    </form>
  </aside>

  <section class="content-pane" data-mailbox-shortcuts>
    <div class="pane-header">
      <div>
        <p class="eyebrow">Folder</p>
        <h1><?= $view->escape($folder) ?></h1>
      </div>
      <a class="button button-primary" href="/compose" data-compose-link>Compose</a>
    </div>

    <form method="get" action="/mailbox" class="search-bar">
      <input type="hidden" name="folder" value="<?= $view->escape($folder) ?>">
      <label>
        <span>Search mail</span>
        <input
          type="search"
          name="q"
          value="<?= $view->escape($query) ?>"
          placeholder="Search this folder"
          data-mailbox-search
        >
      </label>
      <details class="advanced-search" <?= $advancedSearchOpen ? 'open' : '' ?>>
        <summary>Advanced search</summary>
        <div class="advanced-search-grid">
          <label>
            <span>From</span>
            <input type="search" name="from" value="<?= $view->escape($searchCriteria->from ?? '') ?>">
          </label>
          <label>
            <span>To</span>
            <input type="search" name="to" value="<?= $view->escape($searchCriteria->to ?? '') ?>">
          </label>
          <label>
            <span>Subject</span>
            <input type="search" name="subject" value="<?= $view->escape($searchCriteria->subject ?? '') ?>">
          </label>
          <div class="search-toggles">
            <label class="check-row">
              <input type="checkbox" name="unseen" value="1" <?= $searchCriteria->unseenOnly ? 'checked' : '' ?>>
              <span>Unread only</span>
            </label>
            <label class="check-row">
              <input type="checkbox" name="flagged" value="1" <?= $searchCriteria->flaggedOnly ? 'checked' : '' ?>>
              <span>Flagged only</span>
            </label>
          </div>
        </div>
      </details>
      <button class="button button-secondary" type="submit">Search</button>
    </form>

    <?php if ($warning !== null) : ?>
      <div class="alert alert-warning" role="status" aria-live="polite"><?= $view->escape($warning) ?></div>
    <?php endif; ?>

    <div class="mailbox-status" aria-label="Mailbox status" aria-live="polite">
      <span class="status-pill <?= $view->escape($statusClass) ?>"><?= $view->escape($statusLabel) ?></span>
      <?php if ($quotaPercent !== null) : ?>
        <span class="status-pill">Quota <?= $quotaPercent ?>%</span>
      <?php endif; ?>
      <?php foreach ($capabilityLabels as $label) : ?>
        <span class="status-pill"><?= $view->escape($label) ?></span>
      <?php endforeach; ?>
      <?php if ($mailboxRights->available()) : ?>
        <span class="status-pill" title="IMAP MYRIGHTS <?= $view->escape($mailboxRights->raw) ?>">
          Rights <?= $view->escape(implode(', ', $rightLabels) ?: $mailboxRights->raw) ?>
        </span>
      <?php endif; ?>
    </div>

    <?php if ($messages !== [] && $messageActionButtonsAvailable) : ?>
      <form id="bulk-message-form" method="post" action="/message/action" class="bulk-actions">
        <?= $view->csrfInput($csrfToken) ?>
        <input type="hidden" name="folder" value="<?= $view->escape($folder) ?>">
        <input type="hidden" name="q" value="<?= $view->escape($canonicalSearchQuery) ?>">
        <input type="hidden" name="page" value="<?= $page ?>">
        <label class="check-row bulk-select-all">
          <input type="checkbox" data-select-all>
          <span>Select all</span>
        </label>
        <div class="bulk-buttons">
          <?php if ($canChangeSeen) : ?>
            <button name="action" value="seen" type="submit">Read</button>
            <button name="action" value="unseen" type="submit">Unread</button>
          <?php endif; ?>
          <?php if ($canWriteFlags) : ?>
            <button name="action" value="flag" type="submit">Flag</button>
            <button name="action" value="unflag" type="submit">Unflag</button>
          <?php endif; ?>
          <?php if ($canDeleteMessages) : ?>
            <button name="action" value="archive" type="submit">Archive</button>
            <button name="action" value="delete" type="submit">Delete</button>
          <?php endif; ?>
          <?php if ($targetFolders !== [] && ($canMoveMessages || $canCopyMessages)) : ?>
            <select name="target_folder" aria-label="Bulk target folder">
                <?php foreach ($targetFolders as $target) : ?>
                <option value="<?= $view->escape($target->name) ?>"><?= $view->escape($target->displayName) ?></option>
                <?php endforeach; ?>
            </select>
                <?php if ($canMoveMessages) : ?>
              <button name="action" value="move" type="submit">Move</button>
                <?php endif; ?>
                <?php if ($canCopyMessages) : ?>
              <button name="action" value="copy" type="submit">Copy</button>
                <?php endif; ?>
          <?php endif; ?>
        </div>
      </form>
        <?php if ($targetFolders !== [] && $canMoveMessages) : ?>
        <form method="post" action="/message/action" data-drag-move-form hidden>
            <?= $view->csrfInput($csrfToken) ?>
          <input type="hidden" name="folder" value="<?= $view->escape($folder) ?>">
          <input type="hidden" name="q" value="<?= $view->escape($canonicalSearchQuery) ?>">
          <input type="hidden" name="page" value="<?= $page ?>">
          <input type="hidden" name="id" value="" data-drag-message-id>
          <input type="hidden" name="target_folder" value="" data-drag-target-folder>
          <input type="hidden" name="action" value="move">
        </form>
        <?php endif; ?>
    <?php endif; ?>

    <div class="message-list" role="list" aria-label="Messages">
      <?php if ($messages === []) : ?>
        <div class="empty-state">
          <h2>No messages loaded</h2>
          <p>Connect a production mailbox to load messages.</p>
        </div>
      <?php endif; ?>
      <?php foreach ($messageRows as $rowIndex => $row) : ?>
            <?php
        /** @var Mailika\Mail\MessageEnvelope $message */
            $message = $row['message'];
            $threadPosition = (int) $row['thread_position'];
            $threadCount = (int) $row['thread_count'];
            ?>
            <?php
            $messageUrl = '/message?folder=' . urlencode($folder) . '&id=' . urlencode($message->id);
            $replyUrl = '/compose?mode=reply&folder=' . urlencode($folder) . '&id=' . urlencode($message->id);
            $messageRowClasses = trim(
                'message-row '
                . ($message->seen ? '' : 'is-unread ')
                . ($threadPosition > 0 ? 'is-thread-child' : ''),
            );
            ?>
        <div
          class="<?= $view->escape($messageRowClasses) ?>"
          data-message-row
          data-message-id="<?= $view->escape($message->id) ?>"
          data-message-url="<?= $view->escape($messageUrl) ?>"
          data-reply-url="<?= $view->escape($replyUrl) ?>"
          draggable="<?= $targetFolders !== [] && $canMoveMessages ? 'true' : 'false' ?>"
          tabindex="<?= $rowIndex === 0 ? '0' : '-1' ?>"
          role="listitem"
          aria-posinset="<?= $rowIndex + 1 ?>"
          aria-setsize="<?= count($messageRows) ?>"
        >
            <?php if ($messageActionButtonsAvailable) : ?>
            <input
              class="message-select"
              type="checkbox"
              name="selected_ids[]"
              value="<?= $view->escape($message->id) ?>"
              form="bulk-message-form"
              data-message-select
              aria-label="Select <?= $view->escape($message->subject) ?>"
            >
            <?php endif; ?>
          <a class="message-link" href="<?= $view->escape($messageUrl) ?>" data-message-link>
            <span class="message-from"><?= $view->escape($message->from) ?></span>
            <span class="message-subject">
              <?php if ($threadPosition === 0 && $threadCount > 1) : ?>
                <span class="thread-count" aria-label="<?= $threadCount ?> messages in thread">
                    <?= $threadCount ?>
                </span>
              <?php endif; ?>
              <?= $message->flagged ? 'Flagged ' : '' ?><?= $view->escape($message->subject) ?>
            </span>
            <span class="message-date"><?= $view->escape($message->date) ?></span>
          </a>
            <?php if ($messageActionButtonsAvailable) : ?>
            <form method="post" action="/message/action" class="row-actions">
                <?= $view->csrfInput($csrfToken) ?>
              <input type="hidden" name="folder" value="<?= $view->escape($folder) ?>">
              <input type="hidden" name="id" value="<?= $view->escape($message->id) ?>">
              <input type="hidden" name="q" value="<?= $view->escape($canonicalSearchQuery) ?>">
              <input type="hidden" name="page" value="<?= $page ?>">
                <?php if ($canChangeSeen) : ?>
                <button name="action" value="<?= $message->seen ? 'unseen' : 'seen' ?>" type="submit">
                    <?= $message->seen ? 'Unread' : 'Read' ?>
                </button>
                <?php endif; ?>
                <?php if ($canWriteFlags) : ?>
                <button name="action" value="<?= $message->flagged ? 'unflag' : 'flag' ?>" type="submit">
                    <?= $message->flagged ? 'Unflag' : 'Flag' ?>
                </button>
                <?php endif; ?>
                <?php if ($canDeleteMessages) : ?>
                <button name="action" value="archive" type="submit">Archive</button>
                <?php endif; ?>
                <?php if ($targetFolders !== [] && ($canMoveMessages || $canCopyMessages)) : ?>
                <select name="target_folder" aria-label="Target folder">
                    <?php foreach ($targetFolders as $target) : ?>
                    <option value="<?= $view->escape($target->name) ?>">
                        <?= $view->escape($target->displayName) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                    <?php if ($canMoveMessages) : ?>
                  <button name="action" value="move" type="submit">Move</button>
                    <?php endif; ?>
                    <?php if ($canCopyMessages) : ?>
                  <button name="action" value="copy" type="submit">Copy</button>
                    <?php endif; ?>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <nav class="pager" aria-label="Message pages">
      <?php if ($page > 1) : ?>
        <a class="button button-secondary" href="<?= $view->escape($previousPageUrl) ?>">Previous</a>
      <?php endif; ?>
      <?php if (count($messages) >= $preferences->messagesPerPage) : ?>
        <a class="button button-secondary" href="<?= $view->escape($nextPageUrl) ?>">Next</a>
      <?php endif; ?>
    </nav>
  </section>
</section>

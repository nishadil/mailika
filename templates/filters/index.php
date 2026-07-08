<?php

/** @var Mailika\Support\View $view */
/** @var string $csrfToken */
/** @var list<Mailika\Filter\SieveRule> $rules */
/** @var string $script */
/** @var array<string, string> $matchFields */
/** @var array<string, string> $matchOperators */
/** @var array<string, string> $actions */
/** @var string|null $error */
/** @var string|null $notice */
/** @var Mailika\Filter\SieveScriptAnalysis|null $analysis */
/** @var string|null $inspectedScript */
/** @var bool $publisherConfigured */
/** @var string $scriptName */
?>
<section class="content-pane single-pane">
  <div class="pane-header">
    <div>
      <p class="eyebrow">Sieve</p>
      <h1>Mail filters</h1>
    </div>
  </div>

  <?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert"><?= $view->escape($error) ?></div>
  <?php endif; ?>

  <?php if ($notice !== null): ?>
    <div class="alert alert-success" role="status"><?= $view->escape($notice) ?></div>
  <?php endif; ?>

  <form method="post" action="/filters" class="form-grid">
    <?= $view->csrfInput($csrfToken) ?>
    <div class="field-row">
      <label>
        <span>Rule name</span>
        <input type="text" name="name" maxlength="128" required>
      </label>
      <label class="check-row">
        <input type="checkbox" name="enabled" value="1" checked>
        <span>Enabled</span>
      </label>
    </div>
    <div class="field-row three-columns">
      <label>
        <span>Match field</span>
        <select name="match_field">
          <?php foreach ($matchFields as $value => $label): ?>
            <option value="<?= $view->escape($value) ?>" <?= $value === 'subject' ? 'selected' : '' ?>>
              <?= $view->escape($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <span>Match</span>
        <select name="match_operator">
          <?php foreach ($matchOperators as $value => $label): ?>
            <option value="<?= $view->escape($value) ?>"><?= $view->escape($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <span>Value</span>
        <input type="text" name="match_value" maxlength="512" required>
      </label>
    </div>
    <div class="field-row three-columns">
      <label>
        <span>Action</span>
        <select name="action">
          <?php foreach ($actions as $value => $label): ?>
            <option value="<?= $view->escape($value) ?>"><?= $view->escape($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <span>Action target</span>
        <input type="text" name="action_target" maxlength="512" value="Archive">
      </label>
      <label class="check-row">
        <input type="checkbox" name="stop_processing" value="1" checked>
        <span>Stop after match</span>
      </label>
    </div>
    <div class="field-row">
      <label>
        <span>Vacation days</span>
        <input type="number" name="vacation_days" min="1" max="365">
      </label>
      <label>
        <span>Vacation subject</span>
        <input type="text" name="vacation_subject" maxlength="255">
      </label>
    </div>
    <div class="field-row">
      <label>
        <span>Vacation addresses</span>
        <textarea name="vacation_addresses" rows="3"></textarea>
      </label>
      <label>
        <span>Excluded senders</span>
        <textarea name="vacation_excluded_senders" rows="3"></textarea>
      </label>
    </div>
    <button class="button button-primary" type="submit">Save filter</button>
  </form>

  <div class="data-list">
    <?php if ($rules === []): ?>
      <div class="empty-state">
        <h2>No filters</h2>
      </div>
    <?php endif; ?>
    <?php foreach ($rules as $rule): ?>
      <div class="data-row">
        <strong><?= $view->escape($rule->name) ?><?= $rule->enabled ? '' : ' disabled' ?></strong>
        <span>
          <?= $view->escape($matchFields[$rule->matchField] ?? $rule->matchField) ?>
          <?= $view->escape(strtolower($matchOperators[$rule->matchOperator] ?? $rule->matchOperator)) ?>
          <?= $view->escape($rule->matchValue) ?>
        </span>
        <span>
          <?= $view->escape($actions[$rule->action] ?? $rule->action) ?>
          <?= $rule->actionTarget !== null ? $view->escape(' ' . $rule->actionTarget) : '' ?>
        </span>
        <?php if ($rule->action === 'vacation' && ($rule->vacationDays !== null || $rule->vacationSubject !== null)): ?>
          <span>
            <?= $rule->vacationDays !== null ? $view->escape($rule->vacationDays . ' days') : '' ?>
            <?= $rule->vacationSubject !== null ? $view->escape(' ' . $rule->vacationSubject) : '' ?>
          </span>
        <?php endif; ?>
        <?php if ($rule->action === 'vacation' && $rule->vacationExcludedSenders !== []): ?>
          <span>Except <?= $view->escape(implode(', ', $rule->vacationExcludedSenders)) ?></span>
        <?php endif; ?>
        <?php if ($rule->action === 'vacation' && $rule->vacationAddresses !== []): ?>
          <span>For aliases <?= $view->escape(implode(', ', $rule->vacationAddresses)) ?></span>
        <?php endif; ?>
        <?php if ($rule->id !== null): ?>
          <form method="post" action="/filters/delete" class="inline-form">
            <?= $view->csrfInput($csrfToken) ?>
            <input type="hidden" name="id" value="<?= (int) $rule->id ?>">
            <button class="button button-danger" type="submit">Delete</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <details class="code-details">
    <summary>Sieve script preview</summary>
    <pre class="code-preview"><code><?= $view->escape($script) ?></code></pre>
  </details>

  <details class="code-details">
    <summary>Inspect existing Sieve script</summary>
    <form method="post" action="/filters/inspect" class="form-grid">
      <?= $view->csrfInput($csrfToken) ?>
      <label>
        <span>Sieve script</span>
        <textarea name="sieve_script" rows="10" maxlength="65536"><?= $view->escape($inspectedScript ?? '') ?></textarea>
      </label>
      <button class="button button-secondary" type="submit">Inspect script</button>
    </form>
  </details>

  <?php if ($analysis !== null): ?>
    <div class="data-row">
      <strong>Sieve inspection</strong>
      <span><?= $analysis->bytes ?> bytes across <?= $analysis->lines ?> lines</span>
      <span><?= $analysis->mailikaOwned ? 'Mailika generated' : 'External or unknown origin' ?></span>
      <span>
        Extensions:
        <?= $analysis->requiredExtensions === [] ? 'none' : $view->escape(implode(', ', $analysis->requiredExtensions)) ?>
      </span>
      <span>
        Actions:
        <?= $analysis->actions === [] ? 'none' : $view->escape(implode(', ', $analysis->actions)) ?>
      </span>
      <?php if ($analysis->warnings === []): ?>
        <span>No inspection warnings.</span>
      <?php else: ?>
        <?php foreach ($analysis->warnings as $warning): ?>
          <span><?= $view->escape($warning) ?></span>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($analysis->canImportAsLocalRules() && $inspectedScript !== null): ?>
        <form method="post" action="/filters/import" class="inline-form">
          <?= $view->csrfInput($csrfToken) ?>
          <textarea name="sieve_script" hidden><?= $view->escape($inspectedScript) ?></textarea>
          <button class="button button-secondary" type="submit">Import Mailika rules</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($publisherConfigured): ?>
    <form method="post" action="/filters/publish" class="publish-form">
      <?= $view->csrfInput($csrfToken) ?>
      <span>Script <?= $view->escape($scriptName) ?></span>
      <button class="button button-primary" type="submit">Publish filters</button>
    </form>
  <?php else: ?>
    <div class="alert alert-warning filter-publish-status">ManageSieve publishing is not configured.</div>
  <?php endif; ?>
</section>

import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

async function signIn(page: Page) {
  const email = `smoke+${Date.now()}-${Math.random().toString(16).slice(2)}@example.com`;

  await page.goto('/login');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password').fill('secret-password');
  await page.locator('input[name="imap_host"]').fill('fixture.local');
  await page.locator('input[name="imap_port"]').fill('993');
  await page.locator('input[name="smtp_host"]').fill('smtp.fixture.local');
  await page.locator('input[name="smtp_port"]').fill('587');
  await page.getByRole('button', { name: 'Sign in' }).click();

  await expect(page).toHaveURL(/\/mailbox/);
  await expect(page.getByText(email)).toBeVisible();
  return email;
}

async function expectNoHorizontalOverflow(page: Page) {
  const dimensions = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth
  }));

  expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.clientWidth + 1);
}

async function expectNoFirstPartyAccessibilityViolations(page: Page) {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .exclude('[data-mail-body]')
    .analyze();

  expect(results.violations).toEqual([]);
}

test('login screen renders on desktop and mobile', async ({ page }) => {
  await page.goto('/login');
  await expect(page.getByRole('heading', { name: 'Sign in to your mailbox' })).toBeVisible();

  const stylesheets = await page
    .locator('link[rel="stylesheet"]')
    .evaluateAll((links) => links.map((link) => (link as HTMLLinkElement).href));

  expect(stylesheets.length).toBeGreaterThan(0);
  for (const href of stylesheets) {
    const response = await page.request.get(href);
    expect(response.ok()).toBeTruthy();
    expect(response.headers()['content-type']).toContain('text/css');
  }

  const appliedStyles = await page.evaluate(() => {
    const body = window.getComputedStyle(document.body);
    const brandMark = window.getComputedStyle(document.querySelector('.brand-mark') as HTMLElement);
    const authPanel = window.getComputedStyle(document.querySelector('.auth-panel') as HTMLElement);

    return {
      bodyMargin: body.marginTop,
      brandDisplay: brandMark.display,
      brandRadius: brandMark.borderRadius,
      panelBackground: authPanel.backgroundColor
    };
  });

  expect(appliedStyles.bodyMargin).toBe('0px');
  expect(appliedStyles.brandDisplay).toBe('grid');
  expect(appliedStyles.brandRadius).toBe('6px');
  expect(appliedStyles.panelBackground).not.toBe('rgba(0, 0, 0, 0)');

  await page.setViewportSize({ width: 390, height: 844 });
  await expect(page.getByLabel('Email address')).toBeVisible();
});

test('accessibility baseline exposes landmarks, skip link, focus styles, and reduced motion', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto('/login');

  await expect(page.getByRole('banner')).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Primary' })).toBeVisible();
  await expect(page.getByRole('main')).toHaveAttribute('id', 'main-content');
  await expect(page.getByRole('link', { name: 'Skip to content' })).toHaveAttribute('href', '#main-content');

  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/#main-content$/);

  await page.getByLabel('Email address').focus();
  const focusStyle = await page.getByLabel('Email address').evaluate((element) => {
    const style = window.getComputedStyle(element);
    return {
      outlineStyle: style.outlineStyle,
      outlineWidth: style.outlineWidth,
    };
  });
  expect(focusStyle.outlineStyle).not.toBe('none');
  expect(Number.parseFloat(focusStyle.outlineWidth)).toBeGreaterThan(0);

  const reducedMotion = await page.locator('.skip-link').evaluate((element) => {
    const style = window.getComputedStyle(element);
    return {
      animationName: style.animationName,
      transitionDuration: style.transitionDuration,
    };
  });
  expect(reducedMotion.animationName).toBe('none');
  expect(reducedMotion.transitionDuration).toBe('0s');
});

test('login screen negotiates supported locale before sign in', async ({ browser }) => {
  const context = await browser.newContext({
    baseURL: 'http://127.0.0.1:8090',
    locale: 'fa-IR',
  });
  const page = await context.newPage();
  await page.goto('/login');

  await expect(page.locator('html')).toHaveAttribute('lang', 'fa');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.getByLabel('Email address')).toBeVisible();
  await context.close();
});

test('first-party screens pass automated WCAG checks', async ({ page }) => {
  await page.goto('/login');
  await expectNoFirstPartyAccessibilityViolations(page);

  await signIn(page);
  await expectNoFirstPartyAccessibilityViolations(page);

  await page.getByRole('link', { name: 'Compose' }).first().click();
  await expect(page.getByRole('heading', { name: 'Compose', exact: true })).toBeVisible();
  await expectNoFirstPartyAccessibilityViolations(page);

  await page.getByRole('link', { name: 'Contacts' }).click();
  await expect(page.getByRole('heading', { name: 'Contacts', exact: true })).toBeVisible();
  await expectNoFirstPartyAccessibilityViolations(page);

  await page.getByRole('link', { name: 'Filters' }).click();
  await expect(page.getByRole('heading', { name: 'Mail filters', exact: true })).toBeVisible();
  await expectNoFirstPartyAccessibilityViolations(page);

  await page.getByRole('link', { name: 'Settings' }).click();
  await expect(page.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible();
  await expectNoFirstPartyAccessibilityViolations(page);
});

test('mobile authenticated mailbox navigation keeps the layout usable', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await signIn(page);

  await expect(page.getByRole('heading', { name: 'INBOX' })).toBeVisible();
  await expect(page.locator('[data-message-row][data-message-id="1001"]')).toBeVisible();
  await expectNoHorizontalOverflow(page);

  await page.getByRole('link', { name: 'Compose' }).first().click();
  await expect(page.getByRole('heading', { name: 'Compose' })).toBeVisible();
  await expect(page.getByLabel('To')).toBeVisible();
  await expectNoHorizontalOverflow(page);

  await page.getByRole('link', { name: 'Settings' }).click();
  await page.getByLabel('Locale').selectOption('ar');
  await page.getByLabel('Theme').selectOption('dark');
  await page.getByRole('button', { name: 'Save settings' }).click();

  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await expectNoHorizontalOverflow(page);

  await page.getByRole('link', { name: 'Mailbox', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'INBOX' })).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
});

test('authenticated mailbox can read sanitized fixture mail and mutate flags', async ({ page }) => {
  await signIn(page);

  await expect(page.getByRole('heading', { name: 'INBOX' })).toBeVisible();
  await expect(page.getByText('Live')).toBeVisible();
  const welcomeRow = page.locator('[data-message-row][data-message-id="1001"]');
  const reportRow = page.locator('[data-message-row][data-message-id="1002"]');
  await expect(welcomeRow).toContainText('Welcome to Mailika');
  await expect(welcomeRow).toHaveAttribute('tabindex', '0');

  await page.keyboard.press('End');
  await expect(reportRow).toBeFocused();
  await page.keyboard.press('Home');
  await expect(welcomeRow).toBeFocused();
  await page.keyboard.press('x');
  await expect(welcomeRow.locator('[data-message-select]')).toBeChecked();
  await page.keyboard.press('Space');
  await expect(welcomeRow.locator('[data-message-select]')).not.toBeChecked();

  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Welcome to Mailika' })).toBeVisible();
  await expect(page.locator('[data-mail-body] script')).toHaveCount(0);
  await expect(page.locator('[data-mail-body] img[src^="https://"]')).toHaveCount(0);
  await expect(page.locator('[data-mail-body] img[src*="/attachment"]')).toHaveCount(1);
  await expect(page.getByRole('link', { name: 'mailika-logo.png' })).toBeVisible();

  const attachment = await page.request.get('/attachment?folder=INBOX&message=1001&attachment=logo&inline=1');
  expect(attachment.ok()).toBeTruthy();
  expect(attachment.headers()['content-type']).toContain('image/png');

  await page.getByRole('button', { name: 'Flag' }).click();
  await expect(page).toHaveURL(/\/mailbox/);
  await expect(page.locator('[data-message-row][data-message-id="1001"]')).toContainText('Flagged Welcome to Mailika');
});

test('advanced mailbox search filters and saves canonical searches', async ({ page }) => {
  await signIn(page);

  await page.getByText('Advanced search').click();
  await page.getByLabel('From').fill('reports@example.com');
  await page.getByLabel('Subject').fill('Quarterly');
  await page.getByLabel('Flagged only').check();
  await page.getByRole('button', { name: 'Search' }).click();

  await expect(page.locator('[data-message-row][data-message-id="1002"]')).toContainText('Quarterly security report');
  await expect(page.locator('[data-message-row][data-message-id="1001"]')).toHaveCount(0);

  const saveSearch = page.locator('form[action="/searches"]');
  await saveSearch.getByLabel('Save search').fill('Flagged reports');
  await saveSearch.getByRole('button', { name: 'Save' }).click();

  const savedSearch = page.locator('.saved-search-row').filter({ hasText: 'Flagged reports' });
  await expect(savedSearch).toBeVisible();
  await savedSearch.getByRole('link', { name: 'Flagged reports' }).click();
  await expect(page.locator('[data-message-row][data-message-id="1002"]')).toContainText('Quarterly security report');
  await expect(page.getByLabel('From')).toHaveValue('reports@example.com');
  await expect(page.getByLabel('Subject')).toHaveValue('Quarterly');
  await expect(page.getByLabel('Flagged only')).toBeChecked();
});

test('contacts feed compose suggestions and drafts persist in the session store', async ({ page }) => {
  await signIn(page);

  await page.getByRole('link', { name: 'Contacts' }).click();
  await page.getByLabel('New group').fill('Engineering');
  await page.getByRole('button', { name: 'Create group' }).click();
  await expect(page.locator('.compact-list .data-row').filter({ hasText: 'Engineering' })).toBeVisible();

  await page.getByLabel('Name').fill('Alice Example');
  await page.getByLabel('Email').fill('alice@example.com');
  await page.locator('form[action="/contacts"] select[name="group_id"]').selectOption({ label: 'Engineering' });
  await page.getByRole('button', { name: 'Add contact' }).click();
  await expect(page.getByText('Alice Example')).toBeVisible();
  await expect(page.getByText('alice@example.com')).toBeVisible();

  await page.getByRole('link', { name: 'Compose' }).click();
  await page.getByLabel('To').focus();
  await page.getByRole('button', { name: /Alice Example/ }).click();
  await expect(page.getByLabel('To')).toHaveValue('alice@example.com, ');
  await page.getByLabel('Subject').fill('Project update');
  await page.getByLabel('Body').fill('Draft body from Playwright');
  await page.getByRole('button', { name: 'Save draft' }).click();

  await expect(page).toHaveURL(/\/drafts/);
  await expect(page.getByRole('link', { name: 'Project update' })).toBeVisible();

  await page.getByRole('link', { name: 'Project update' }).click();
  await expect(page.getByLabel('To')).toHaveValue('alice@example.com,');
  await expect(page.getByLabel('Subject')).toHaveValue('Project update');
  await expect(page.getByLabel('Body')).toHaveValue('Draft body from Playwright');
});

test('filters can save local Sieve rules and render a script preview', async ({ page }) => {
  await signIn(page);

  await page.getByRole('link', { name: 'Filters' }).click();
  await page.getByLabel('Rule name').fill('Invoices');
  await page.getByLabel('Value').fill('invoice');
  await page.getByLabel('Action target').fill('Archive');
  await page.getByRole('button', { name: 'Save filter' }).click();

  const ruleRow = page.locator('.data-row').filter({ hasText: 'Invoices' });
  await expect(ruleRow).toBeVisible();
  await expect(ruleRow).toContainText('Subject contains invoice');

  await page.getByLabel('Rule name').fill('Escalate');
  await page.getByLabel('Value').fill('urgent');
  await page.locator('select[name="action"]').selectOption('redirect');
  await page.getByLabel('Action target').fill('ops@example.com');
  await page.getByRole('button', { name: 'Save filter' }).click();

  await expect(page.locator('.data-row').filter({ hasText: 'Escalate' })).toContainText('Redirect ops@example.com');

  await page.getByLabel('Rule name').fill('Away');
  await page.getByLabel('Value').fill('vacation');
  await page.locator('select[name="action"]').selectOption('vacation');
  await page.getByLabel('Action target').fill('I am away.');
  await page.getByLabel('Vacation days').fill('7');
  await page.getByLabel('Vacation subject').fill('Away from mail');
  await page.getByLabel('Vacation addresses').fill('smoke@example.com\nteam@example.com');
  await page.getByLabel('Excluded senders').fill('noreply@example.com\nalerts@example.com');
  await page.getByRole('button', { name: 'Save filter' }).click();

  await expect(page.locator('.data-row').filter({ hasText: 'Away' })).toContainText('7 days Away from mail');
  await expect(page.locator('.data-row').filter({ hasText: 'Away' })).toContainText(
    'For aliases smoke@example.com, team@example.com',
  );
  await expect(page.locator('.data-row').filter({ hasText: 'Away' })).toContainText(
    'Except noreply@example.com, alerts@example.com',
  );
  await page.getByText('Sieve script preview').click();
  await expect(page.locator('pre code')).toContainText('fileinto "Archive";');
  await expect(page.locator('pre code')).toContainText('redirect "ops@example.com";');
  await expect(page.locator('pre code')).toContainText(
    'not header :contains "From" ["noreply@example.com", "alerts@example.com"]',
  );
  await expect(page.locator('pre code')).toContainText(
    'vacation :addresses ["smoke@example.com", "team@example.com"] :days 7 :subject "Away from mail" "I am away.";',
  );
  const generatedScript = await page.locator('pre code').textContent();
  expect(generatedScript).toContain('# Generated by Mailika');

  await page.getByText('Inspect existing Sieve script').click();
  await page.locator('textarea[name="sieve_script"]').fill(generatedScript ?? '');
  await page.getByRole('button', { name: 'Inspect script' }).click();
  await expect(page.locator('.data-row').filter({ hasText: 'Sieve inspection' })).toContainText('No inspection warnings.');
  await page.getByRole('button', { name: 'Import Mailika rules' }).click();
  await expect(page.getByText('Imported 3 Mailika-generated filter(s).')).toBeVisible();

  await page.getByText('Inspect existing Sieve script').click();
  await page.locator('textarea[name="sieve_script"]').fill('require ["fileinto", "include"];\npipe "spamc";\n');
  await page.getByRole('button', { name: 'Inspect script' }).click();
  await expect(page.locator('.data-row').filter({ hasText: 'Sieve inspection' })).toContainText('include');
  await expect(page.locator('.data-row').filter({ hasText: 'Sieve inspection' })).toContainText('pipe');
  await expect(page.locator('.data-row').filter({ hasText: 'Sieve inspection' })).toContainText(
    'cannot be imported automatically',
  );
  await expect(page.getByRole('button', { name: 'Import Mailika rules' })).toHaveCount(0);
});

test('account profiles store metadata without secrets and switch from the mailbox sidebar', async ({ page }) => {
  const email = await signIn(page);

  await page.getByRole('link', { name: 'Settings' }).click();
  await page.getByRole('link', { name: 'Manage account profiles' }).click();
  await expect(page.getByRole('heading', { name: 'Account profiles' })).toBeVisible();

  await page.getByRole('button', { name: 'Save current account profile' }).click();
  const profile = page.locator('.data-row').filter({ hasText: 'fixture.local' });
  await expect(profile).toBeVisible();
  await expect(profile).toContainText('smtp.fixture.local');
  await expect(page.locator('input[name="password"]')).toHaveCount(0);
  await expect(page.getByText('secret-password')).toHaveCount(0);

  const addProfile = page.locator('form.form-grid[action="/accounts"]');
  await addProfile.getByLabel('Label').fill('Backup mailbox');
  await addProfile.getByLabel('Email address').fill('backup@example.com');
  await addProfile.locator('input[name="imap_host"]').fill('imap.backup.example.com');
  await addProfile.locator('input[name="imap_port"]').fill('993');
  await addProfile.locator('input[name="smtp_host"]').fill('smtp.backup.example.com');
  await addProfile.locator('input[name="smtp_port"]').fill('465');
  await addProfile.locator('select[name="smtp_tls"]').selectOption('smtps');
  await addProfile.getByRole('button', { name: 'Add profile' }).click();

  await page.getByRole('link', { name: 'Mailbox', exact: true }).click();
  await expect(page.getByText('Accounts')).toBeVisible();

  const currentSidebarProfile = page.locator('.profile-row').filter({ hasText: email });
  await expect(currentSidebarProfile).toBeVisible();
  await expect(currentSidebarProfile.getByRole('button', { name: 'Use' })).toHaveCount(0);

  const backupSidebarProfile = page.locator('.profile-row').filter({ hasText: 'Backup mailbox' });
  await expect(backupSidebarProfile).toContainText('backup@example.com');
  await expect(backupSidebarProfile).not.toContainText('imap.backup.example.com');
  await expect(backupSidebarProfile).not.toContainText('smtp.backup.example.com');
  await backupSidebarProfile.getByRole('button', { name: 'Use' }).click();

  await expect(page).toHaveURL(/\/login/);
  await expect(page.getByLabel('Email address')).toHaveValue('backup@example.com');
  await expect(page.locator('input[name="imap_host"]')).toHaveValue('imap.backup.example.com');
  await expect(page.locator('input[name="smtp_host"]')).toHaveValue('smtp.backup.example.com');
  await expect(page.locator('input[name="password"]')).toHaveValue('');
});

test('settings update layout preferences and logout destroys the session', async ({ page }) => {
  await signIn(page);

  await page.getByRole('link', { name: 'Settings' }).click();
  await page.getByLabel('Theme').selectOption('dark');
  await page.getByLabel('Messages per page').selectOption('100');
  await page.getByRole('button', { name: 'Save settings' }).click();

  await expect(page.getByRole('status')).toHaveText('Settings saved.');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

  await page.getByRole('link', { name: 'Mailbox', exact: true }).click();
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page).toHaveURL(/\/login/);
  await page.goto('/mailbox');
  await expect(page).toHaveURL(/\/login/);
});

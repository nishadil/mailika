# Accessibility

Mailika targets WCAG 2.2 AA for the application shell and first-party webmail workflows. User-supplied email content is hostile third-party content; Mailika sanitizes and constrains it, but it cannot guarantee that arbitrary messages are accessible.

## Baseline

- Pages expose a semantic `header`, primary `nav`, and `main` landmark.
- A skip link moves keyboard users directly to the main content region.
- Form controls have visible labels or explicit accessible names.
- Alerts and save notices use `role="alert"` or `role="status"` where the user needs feedback.
- Keyboard users can sign in, navigate the message list, open mail, select messages, search, compose, save drafts, update settings, and sign out.
- Focus indicators are visible for links, buttons, form controls, and mailbox rows.
- The layout supports mobile viewport widths without horizontal page overflow.
- `lang`, `dir`, and theme attributes come from user preferences after sign-in, with RTL support for RTL locales.
- Unauthenticated pages negotiate a supported locale from `Accept-Language` and fall back to English for unsupported or malformed values.
- Motion is minimized when the user enables reduced-motion preferences.
- Playwright runs axe-based WCAG checks against first-party app chrome and excludes hostile third-party email bodies from automated conformance claims.

## Release Review

Run the automated browser checks through `npm run check`; this includes axe checks for first-party screens. Before a release candidate, also perform a focused manual pass:

1. Navigate the login, mailbox, message, compose, contacts, filters, settings, and logout flows using only the keyboard.
2. Confirm focus order is visible and follows the visual order.
3. Confirm all forms announce labels, errors, and success notices clearly with a screen reader.
4. Confirm desktop and mobile layouts do not clip controls, overlap text, or introduce horizontal page scrolling.
5. Confirm dark mode, system theme, negotiated locale, and RTL locale settings remain usable.
6. Confirm sanitized email content cannot create keyboard traps, active scripts, remote-image leaks, or unsafe attachment rendering.

## Known Boundaries

- Third-party message bodies may contain inaccessible wording, image alt text, tables, or visual structure after sanitization.
- Drag/drop folder moves are an enhancement; equivalent form-based move actions must remain available.
- Localization coverage is foundational in v1 and should expand after core webmail stability.

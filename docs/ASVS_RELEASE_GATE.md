# ASVS Release Gate

Mailika targets OWASP ASVS 5.0 Level 2. A release candidate should not be tagged until each item below is either verified, explicitly not applicable, or tracked as a release blocker.

## Scope

- Confirm the release scope is still webmail for existing IMAP/SMTP servers.
- Confirm Mailika does not persist IMAP, SMTP, ManageSieve, or OpenPGP private-key passphrases.
- Confirm production defaults do not require debug mode, fixture mailbox adapters, public writable paths, or local-only secrets.
- Confirm [Threat Model](THREAT_MODEL.md) still matches the released architecture, trust boundaries, and deferred scope.
- Confirm [Data Handling](DATA_HANDLING.md) still matches persisted fields, logs, metrics, audit events, exports, caches, uploads, and backups.

## Authentication And Session

- Session IDs regenerate after login and account-profile switching.
- Logout clears the encrypted credential vault and destroys the server-side session.
- Cookies are HttpOnly, SameSite=Lax, secure in production, and named by `SESSION_NAME`.
- `APP_KEY` is generated with `php bin/mailika-key` and is not committed or logged.
- Redis sessions fail fast when `SESSION_DRIVER=redis` but Redis support is unavailable.

## Access Control

- Every mailbox, contact, identity, draft, saved-search, filter, and account-profile operation is scoped to the authenticated mailbox identity.
- IMAP ACL-derived restrictions suppress unsupported mailbox actions in both UI and POST handlers.
- Attachment downloads require the active encrypted session credentials and cannot be fetched anonymously.
- Public document root is only `public/`; source, config, database, templates, storage, logs, and vendor paths are denied by sample web-server configs.

## Input And Output Safety

- HTML email rendering uses `HtmlSanitizer`.
- Remote images remain disabled by default; known `cid:` inline images are rewritten to authenticated attachment URLs before sanitization.
- Attachment filenames, MIME types, and response headers are sanitized.
- Active downloaded formats are forced to `application/octet-stream`.
- Oversized mailbox attachments are rejected before download, and server-reported oversized parts are not materialized by the primary IMAP adapter.
- Compose rejects OpenPGP private-key armor in message bodies and validated attachment streams.
- SMTP headers, identity sender values, Reply-To, and recipients are validated and normalized before delivery.

## Credential And Secret Handling

- Mailbox credentials are sealed with Sodium before session storage.
- Logs, audit events, readiness checks, metrics, and error pages do not contain mailbox passwords, server passwords, Redis DSNs, database passwords, or message bodies.
- Account profiles store connection metadata only, never mailbox passwords.
- LDAP bind credentials remain server-side configuration and are never copied into contacts or audit events.
- New persisted fields, logs, metrics, exports, imports, caches, uploads, and backups are reviewed against the data inventory before release.

## Transport And Host Policy

- Production requires TLS-valid IMAP and SMTP with `MAILIKA_REQUIRE_TLS=true`.
- IMAP, SMTP, ManageSieve, and LDAP host allowlists are configured for production deployments.
- Trusted proxy headers are used only when `MAILIKA_TRUSTED_PROXIES` matches the immediate proxy.
- HSTS is emitted for secure production requests.

## Availability And Abuse Controls

- Login throttling is enabled and Redis-backed in multi-node deployments.
- Redis-backed rate limiting fails closed when Redis is configured but unavailable.
- Upload and download limits are enforced by Mailika and mirrored by reverse-proxy/PHP configuration.
- `/readyz` checks required storage paths, configured data-store driver availability, Redis availability/reachability, and runtime support.
- Compatibility evidence is updated for supported fixture or provider results.

## Accessibility And Localization

- Primary pages expose semantic landmarks and accessible form labels.
- Automated axe checks pass for first-party app screens in Playwright.
- Keyboard workflows cover login, mailbox navigation, message reading, compose, contacts, filters, settings, and logout.
- Focus indicators remain visible in light mode, dark mode, mobile layouts, and RTL layouts.
- Reduced-motion preferences are respected by first-party UI transitions.
- Accessibility release notes are reviewed against [Accessibility](ACCESSIBILITY.md).

## Audit And Operations

- Security-relevant events are recorded without secrets.
- Database migrations are applied with `php bin/upgrade`.
- `php bin/install` and `php bin/upgrade` preflight checks pass.
- Backup and restore procedures have been exercised for the release database schema.
- Generated `public/build/` assets are produced from source and not edited by hand.
- Required project metadata, support/security contacts, repository links, license declarations, and public document-root invariants pass the local release metadata check.
- Security advisory, changelog, and disclosure notes are prepared for any vulnerability fix.

## Dependency And Build Gates

- `composer check` including Composer metadata validation, dependency audit, PHPCS, PHPStan, PHPUnit, license check, secret scan, workflow hardening check, and release metadata inventory check.
- `npm run check` including npm audit, TypeScript typecheck, ESLint, Vite build, and Playwright e2e tests.
- CodeQL passes for PHP and JavaScript/TypeScript.
- Dependency review passes on pull requests.
- Container image builds, scans clean with Trivy for high/critical findings, and publishes SBOM/provenance on release.
- GitHub Actions workflows use least-privilege permissions, explicit timeouts, concurrency controls, versioned action references, no `pull_request_target`, and a checked inventory of required release gates.

## Required Evidence

For each release candidate, attach or link:

- CI run URL.
- Container scan result.
- Migration notes.
- Threat model review notes.
- Data handling review notes.
- Accessibility review notes.
- Compatibility matrix notes.
- Backup/restore validation notes.
- Any accepted residual risks with owner and follow-up issue.
- Final evidence note validation result from `composer validate-release-evidence -- release-evidence-vX.Y.Z.md`.

Use [Release Runbook](RELEASE.md) to collect and attach this evidence consistently.

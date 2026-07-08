# Changelog

All notable Mailika changes should be recorded here before a release is tagged.

This project follows a conservative changelog shape inspired by Keep a Changelog, with release tags matching `vX.Y.Z`.

## Unreleased

### Added

- Frameworkless PHP 8.3+ webmail scaffold with Composer autoloading, Vite-managed assets, server-rendered templates, and Docker packaging.
- Core mailbox workflows for authentication, folders, message listing/search/read, compose/send, attachments, contacts, identities, drafts, preferences, filters, account profiles, and audit events.
- Webklex-backed primary IMAP adapter, Symfony Mailer SMTP sender, optional legacy `ext-imap` fallback, and deterministic fixture mailbox adapter for local tests.
- Security baseline covering encrypted session-only mailbox credentials, CSRF protection, login throttling, hostile HTML email sanitization, remote-image blocking, attachment validation, OpenPGP private-key leak blocking, and hardened security headers.
- Production operations support with migrations, install/upgrade preflight checks, health/readiness/metrics endpoints, Redis session/rate-limit options, hardened web-server examples, backup/restore docs, ASVS release gate, and release runbook.
- CI gates for PHP quality/tests/security checks, TypeScript/lint/build/e2e checks, CodeQL, dependency review, container scanning, GHCR publishing, SBOM, and provenance.

### Changed

- Public document root is constrained to `public/`, with generated `public/build/` assets treated as build output and writable runtime paths kept outside the public tree.
- Docker Compose example uses a read-only app filesystem, dropped Linux capabilities, `no-new-privileges`, health-gated dependencies, and named storage volumes.

### Security

- `public/storage/logs/mailika.log` was removed from the public tree.
- Production preflight rejects debug mode, insecure app URLs, insecure session cookies, fixture mailbox adapters, disabled TLS requirements, wildcard production host allowlists, and invalid network ports.
- The primary IMAP adapter avoids materializing server-reported oversized attachments and only loads the requested attachment content during downloads.

### Deferred

- Full OpenPGP encryption/signing awaits a dedicated key-management design.
- Full external Sieve round-trip editing, broader shared-folder workflows, CalDAV/CardDAV, calendar, and tasks remain later roadmap items.

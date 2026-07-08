# Mailika

Mailika is a GPL-3.0-only, self-hosted webmail client for existing IMAP and SMTP servers. It is designed as a frameworkless PHP 8.3+ project with a conservative production structure, small audited dependencies, and a security baseline aligned to OWASP ASVS 5.0 Level 2.

- Website: <https://mailika.com>
- Repository: <https://github.com/nishadil/mailika>
- Security contact: <opensource@nishadil.dev>
- License: GPL-3.0-only
- Support: see [SUPPORT.md](SUPPORT.md)

## Status

This repository currently contains the production-ready scaffold for Mailika v0.1. The app has a front controller, secure session and CSRF primitives, HTML mail sanitization, internal mail contracts, a Webklex-backed IMAP adapter boundary, SMTP sending via Symfony Mailer, persistent app-owned data stores, contact groups, vCard import/export, optional read-only LDAP directory search, saved and advanced mailbox searches, local Sieve rule management with optional ManageSieve publishing, display preferences, DB-backed audit events, readiness checks, server-rendered templates, Vite-managed TypeScript/CSS assets, Docker, CI, and focused tests.

Mailika v1 is scoped as a webmail client. It does not provision or operate mail servers.

## Requirements

- PHP 8.3 minimum; PHP 8.4+ or 8.5+ is preferred for production support lifetime.
- Composer 2.
- Node.js 20+ and npm for frontend assets.
- A production database available through PDO, preferably PostgreSQL.
- `webklex/php-imap` is the primary IMAP adapter; `ext-imap` is only an optional native fallback.
- Sodium, OpenSSL, DOM, mbstring, JSON, ctype, filter, and PDO PHP extensions.

## Local Setup

```bash
composer install
npm install
cp .env.example .env
php bin/mailika-key
php bin/install
php -S 127.0.0.1:8080 -t public public/index.php
```

Place the generated key into `APP_KEY`. Build frontend assets with:

```bash
npm run build
```

`php bin/install` runs deployment preflight checks before migrations; `php bin/upgrade` runs the same runtime, database-driver, storage, network-port, and production-security checks during releases.

For deterministic browser tests or local UI demos without a mail server, set `MAILIKA_IMAP_ADAPTER=fixture`.
Do not use the fixture adapter in production; use the default `webklex` adapter for real IMAP.

Mailbox search accepts plain text plus operators such as `from:reports@example.com`, `to:team@example.com`, `subject:"Quarterly report"`, `is:unread`, and `is:flagged`. Saved searches persist the canonical operator string so advanced filters can be reopened later.

## Verification

```bash
composer check
npm run check
```

Optional external IMAP/SMTP fixture tests use GreenMail:

```bash
docker compose --profile mail-fixtures up -d greenmail
composer fixtures:check -- mail
composer test:external-mail
docker compose --profile mail-fixtures down
```

The same test can run against any disposable GreenMail-compatible IMAP/SMTP service already listening on the configured ports; `composer fixtures:check -- mail` verifies `127.0.0.1:3025` and `127.0.0.1:3143` by default.

Optional ManageSieve publishing tests use the disposable Dovecot/Pigeonhole fixture:

```bash
docker compose --profile sieve-fixtures up -d managesieve
composer fixtures:check -- sieve
composer test:external-sieve
docker compose --profile sieve-fixtures down
```

When Docker is unavailable, the disposable PHP protocol fixture can exercise the same publish flow. Start the fixture in one shell:

```bash
php tests/fixtures/managesieve/server.php --host=127.0.0.1 --port=4190
```

Then run the checks from another shell:

```bash
composer fixtures:check -- sieve
composer test:external-sieve
```

## Security Model

Mailbox credentials are encrypted before being stored in the server-side session and are never persisted in the database. HTML email is treated as hostile input: scripts, event handlers, unsafe CSS, and remote resources are removed or blocked. Remote images default to disabled.

OpenPGP encryption/signing is not implemented yet. Compose blocks OpenPGP private-key armor in bodies and attachments to reduce accidental key leakage.

Contacts, identities, preferences, saved searches, local Sieve rules, non-secret account profiles, metadata cache, and drafts are Mailika-owned data. Account profiles can prefill a future sign-in with email and server metadata, but the mailbox password is still required and is never persisted. Set `MAILIKA_DATA_STORE=database` in production so those records are persisted through PDO; local development can use `auto` to fall back when the configured PDO driver is unavailable.

ManageSieve publishing is disabled unless `MAILIKA_SIEVE_ENABLED=true` and a TLS-valid Sieve endpoint is configured. Publishing reuses the encrypted session credentials and does not persist mailbox passwords. Existing external Sieve scripts can be inspected locally. Mailika-generated scripts can be imported back into editable local rules, while arbitrary external scripts remain inspection-only.

`/healthz` reports liveness. `/readyz` reports runtime readiness, configured data-store driver availability, Redis readiness when configured, and writable storage checks without exposing secrets. `/metrics` exposes low-cardinality Prometheus-style runtime metrics without mailbox or credential data.

Production deployments should enable TLS-only IMAP/SMTP, secure cookies, HSTS, a non-debug environment, centralized logs, and tested database backups. Container images publish to `ghcr.io/nishadil/mailika` with `latest`, `main`, semantic version, and `sha-*` tags plus SBOM/provenance attestations. See [docs/SECURITY_BASELINE.md](docs/SECURITY_BASELINE.md), [docs/ASVS_RELEASE_GATE.md](docs/ASVS_RELEASE_GATE.md), [docs/DATA_HANDLING.md](docs/DATA_HANDLING.md), and [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md).

Release candidates follow [docs/RELEASE.md](docs/RELEASE.md), including local gates, external fixture checks, migration/restore evidence, container scan evidence, and GHCR SBOM/provenance verification.
Security reviews should use [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md) to calibrate trust boundaries, attacker-controlled inputs, and severity.
Provider and mail-server compatibility evidence is tracked in [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md).
Accessibility expectations and manual release checks are tracked in [docs/ACCESSIBILITY.md](docs/ACCESSIBILITY.md).

## Project Layout

- `public/index.php` - front controller.
- `public/build/` - generated Vite assets; do not edit by hand.
- `src/` - frameworkless PHP application code.
- `templates/` - server-rendered PHP views.
- `assets/` - TypeScript and CSS built by Vite.
- `database/migrations/` - Mailika-owned data schema.
- `config/webserver/` - hardened Nginx and Caddy examples.
- `CHANGELOG.md` - release-facing change history.
- `SUPPORT.md` - support scope and issue guidance.
- `docs/ACCESSIBILITY.md` - accessibility baseline and release checklist.
- `docs/COMPATIBILITY.md` - IMAP/SMTP/Sieve compatibility matrix and test checklist.
- `docs/DATA_HANDLING.md` - data inventory, retention model, and review checklist.
- `docs/THREAT_MODEL.md` - repository-scoped security threat model.
- `tests/` - PHPUnit tests.
- `.github/workflows/` - CI and security scanning.

## References

- <https://www.php.net/supported-versions.php>
- <https://roundcube.net/>
- <https://owasp.org/www-project-application-security-verification-standard/>
- <https://cheatsheetseries.owasp.org/>

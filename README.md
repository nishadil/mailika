# Mailika

Mailika is a GPL-3.0-only, self-hosted webmail client for existing IMAP and SMTP servers. It is designed as a frameworkless PHP 8.3+ project with a conservative production structure, small audited dependencies, and a security baseline aligned to OWASP ASVS 5.0 Level 2.

- Website: <https://mailika.com>
- Repository: <https://github.com/nishadil/mailika>
- Security contact: <opensource@nishadil.dev>
- License: GPL-3.0-only

## Status

This repository currently contains the production-ready scaffold for Mailika v0.1. The app has a front controller, secure session and CSRF primitives, HTML mail sanitization, internal mail contracts, a PHP-IMAP adapter boundary, SMTP sending via Symfony Mailer, server-rendered templates, Vite-managed TypeScript/CSS assets, Docker, CI, and focused tests.

Mailika v1 is scoped as a webmail client. It does not provision or operate mail servers.

## Requirements

- PHP 8.3 minimum; PHP 8.4+ or 8.5+ is preferred for production support lifetime.
- Composer 2.
- Node.js 20+ and npm for frontend assets.
- A production database available through PDO, preferably PostgreSQL.
- `ext-imap` for the built-in production IMAP adapter.
- Sodium, OpenSSL, DOM, mbstring, JSON, ctype, filter, and PDO PHP extensions.

## Local Setup

```bash
composer install
npm install
cp .env.example .env
php bin/mailika-key
php -S 127.0.0.1:8080 -t public
```

Place the generated key into `APP_KEY`. Build frontend assets with:

```bash
npm run build
```

## Verification

```bash
composer check
npm run typecheck
npm run lint
npm run build
```

## Security Model

Mailbox credentials are encrypted before being stored in the server-side session and are never persisted in the database. HTML email is treated as hostile input: scripts, event handlers, unsafe CSS, and remote resources are removed or blocked. Remote images default to disabled.

Production deployments should enable TLS-only IMAP/SMTP, secure cookies, HSTS, a non-debug environment, and centralized logs. See [docs/SECURITY_BASELINE.md](docs/SECURITY_BASELINE.md).

## Project Layout

- `public/index.php` - front controller.
- `src/` - frameworkless PHP application code.
- `templates/` - server-rendered PHP views.
- `assets/` - TypeScript and CSS built by Vite.
- `database/migrations/` - Mailika-owned data schema.
- `tests/` - PHPUnit tests.
- `.github/workflows/` - CI and security scanning.

## References

- <https://www.php.net/supported-versions.php>
- <https://roundcube.net/>
- <https://owasp.org/www-project-application-security-verification-standard/>
- <https://cheatsheetseries.owasp.org/>

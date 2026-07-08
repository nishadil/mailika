# Architecture

Mailika is a frameworkless PHP webmail client. The HTTP entrypoint is `public/index.php`, which boots configuration, sessions, security headers, routing, controllers, and templates.

The authoritative inventory for stored, transient, logged, cached, exported, and backed-up data is [Data Handling](DATA_HANDLING.md).

## Modules

- `Auth` manages mailbox credentials, encrypted session storage, and login/logout.
- `Mail` defines IMAP/SMTP contracts and concrete adapters.
- `Crypto` holds defensive cryptography-adjacent safeguards; OpenPGP signing/encryption is not implemented yet.
- `Security` owns CSRF, headers, credential sealing, HTML sanitization, and rate limiting.
- `Database`, `Contact`, and `Preferences` store only Mailika-owned data.
- `Contact`, `Identity`, `Draft`, saved-search, local Sieve-rule, and non-secret account-profile storage expose repository interfaces with database implementations for production and session implementations for local fallback.
- `ContactDirectoryInterface` isolates optional read-only external directory search, with LDAP disabled by default and local contacts remaining the only persisted address-book data.
- `Audit` records security-relevant events without secrets to structured logs and, when database storage is active, to the `audit_events` table.
- `Module` exposes an internal core-module registry used for operational introspection. It is not a public plugin API, and module contracts may still change during v1.

## Mail Boundary

Mailika v1 connects to existing IMAP/SMTP services. It does not create mailboxes, accept inbound SMTP, configure DNS, or operate a mail server stack.

The primary `WebklexMailboxClient` uses a userland IMAP implementation through `webklex/php-imap`. The legacy `PhpImapMailboxClient` remains available only as an optional fallback for deployments that intentionally install `ext-imap`.

`FixtureMailboxClient` is a deterministic session-backed adapter for browser tests and local UI demos. It is not a production mail adapter and must not be used for real mailbox access.

## Filter Boundary

Mailika stores validated Sieve filter rules and can compile them into a constrained Sieve script preview. ManageSieve publishing is optional and disabled by default. When enabled, Mailika uses the active mailbox credentials from the encrypted session, uploads the generated script with PUTSCRIPT, and activates it with SETACTIVE; mailbox passwords are still not persisted.

## Credential Boundary

Mailbox credentials are encrypted with `APP_KEY` before session storage. They are not written to the database, logs, audit events, cache, or templates.

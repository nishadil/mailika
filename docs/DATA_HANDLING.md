# Data Handling

Mailika stores app-owned data needed for self-hosted webmail convenience and keeps mailbox credentials session-only. This document is an operator and reviewer inventory, not a public website privacy policy.

## Data Classes

| Class | Examples | Storage | Retention And Notes |
| --- | --- | --- | --- |
| Session-only secrets | IMAP password, SMTP password, ManageSieve password, active mailbox connection metadata | Server-side session through `CredentialVault` using Sodium authenticated encryption | Controlled by `SESSION_LIFETIME_SECONDS`; cleared on logout; never stored in the database, logs, metrics, audit events, backups, or metadata cache. |
| Deployment secrets | `APP_KEY`, database credentials, Redis DSNs, LDAP bind credentials, package publishing tokens | Environment, `.env`, secret manager, CI secret store | Operator-controlled; must not be committed, rendered, logged, exported, or copied into database rows. |
| App-owned user data | Contacts, contact groups, identities, drafts, preferences, saved searches, local Sieve rules, non-secret account profiles | Configured Mailika data store, preferably database in production | Retained until deleted by the user/operator or pruned by a future retention job. Treat as untrusted when rendered or used in headers. |
| Mailbox-derived metadata cache | Folder name, UID, message ID, subject, sender, recipients, dates, flags, attachment presence, thread references | `message_metadata_cache` when database storage is enabled | Bounded to 10,000 envelope rows per folder. It is a recoverable cache and does not store message bodies or attachment content by default. |
| Mailbox content in transit | Message bodies, MIME parts, attachment bytes, inline image content | Process memory and streamed responses; compose upload temp files during the request lifecycle | Treated as hostile input. Sanitized, validated, size-limited, and not durable unless the user saves a draft through Mailika. |
| Draft content | Draft recipients, subject, body, selected identity, validated attachment metadata where supported | Draft repository in the configured Mailika data store | User-owned app data. Draft content can include private message text and must be protected like mailbox content. |
| Audit events | Event type, mailbox identity, success/failure status, counts, remote address/user agent when available | Structured log sink and `audit_events` table when database storage is active | Security-relevant and secret-free. Do not record passwords, raw SMTP/IMAP responses, message bodies, attachment content, or full searched text. |
| Operational telemetry | `/healthz`, `/readyz`, `/metrics`, build labels, readiness booleans, low-cardinality counters | HTTP responses and monitoring systems | No credentials, mailbox addresses, message metadata, database hosts, Redis DSNs, LDAP queries, or user input. |
| Application logs | Application events, errors, sanitized context | `storage/logs` locally or centralized production logging | Secret-free by policy. Production operators should protect log access and retention separately from the web root. |
| Backups | Database dumps, deployment configuration, secret-manager exports | Operator backup storage | Back up app-owned data and required secrets separately. Do not back up `storage/cache` as authoritative data. Session data is short-lived and should normally be excluded. |

## Do Not Store

Mailika must not persist or intentionally log:

- IMAP, SMTP, or ManageSieve passwords.
- Provider OAuth tokens or future refresh tokens unless a later background-sync design includes key management and a migration plan.
- `APP_KEY`, database passwords, Redis passwords, LDAP bind passwords, CI tokens, signing keys, or package publishing tokens.
- OpenPGP private keys or private-key passphrases.
- Message bodies or attachment bytes in the metadata cache.
- Raw IMAP, SMTP, ManageSieve, or LDAP protocol transcripts that could contain credentials, message bodies, or private search text.

## Retention Principles

- Session credentials expire according to `SESSION_LIFETIME_SECONDS` and are destroyed on logout.
- Database rows are retained until user action, operator action, or a documented retention feature removes them.
- Mailbox metadata cache is bounded and can be rebuilt from the mail server.
- Audit-event retention is operator-controlled; production deployments should define a retention period that balances incident response with privacy and storage limits.
- Backups are operator-controlled and must include restore tests, encryption at rest, restricted access, and documented deletion/rotation policy.

## Change Review Checklist

Use this checklist when a pull request adds or changes migrations, repositories, logs, audit events, metrics, exports, imports, caches, uploads, or backups.

- Identify the new or changed data class.
- Confirm whether the data is app-owned, mailbox-derived, a secret, or transient mailbox content.
- Confirm mailbox credentials and deployment secrets cannot reach the database, logs, metrics, audit events, cache, templates, or release evidence.
- Confirm rendered values are escaped and header values are normalized before use.
- Confirm exports/imports validate filenames, MIME types, content size, character encoding, and authorization scope.
- Confirm metrics remain low-cardinality and exclude user-controlled or private values.
- Update this document, [Threat Model](THREAT_MODEL.md), [ASVS Release Gate](ASVS_RELEASE_GATE.md), and [Release Runbook](RELEASE.md) when the data boundary changes.

## Operator Responsibilities

Operators remain responsible for restricting access to the database, backups, logs, Redis, `.env`, secret managers, and monitoring systems. Production deployments should use HTTPS, TLS-valid mail connectors, restricted host allowlists, centralized secret-free logs, encrypted backups, and a restore process tested before each release.

Suspected data exposure or credential leakage should be reported using [Security Policy](../SECURITY.md).

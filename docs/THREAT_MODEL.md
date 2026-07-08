# Threat Model

This threat model covers the Mailika repository as a self-hosted webmail client for existing IMAP/SMTP services. It is intended for security review, release gating, and future vulnerability triage.

## Overview

Mailika is a frameworkless PHP web application with a public document root at `public/`, server-rendered templates in `templates/`, application code in `src/`, migrations in `database/migrations/`, Vite-managed frontend assets in `assets/`, and operational metadata in `docs/` and `.github/`.

The primary runtime surfaces are:

- Browser-facing HTTP routes created in `src/Bootstrap.php`.
- Mailbox authentication and encrypted credential storage in `src/Auth/`.
- IMAP mailbox access through `src/Mail/WebklexMailboxClient.php`, with `src/Mail/PhpImapMailboxClient.php` as an optional legacy fallback.
- SMTP delivery through `src/Mail/SymfonySmtpSender.php`.
- HTML email rendering through `src/Security/HtmlSanitizer.php`.
- Attachment upload/download validation through `src/Security/AttachmentPolicy.php` and `src/Controller/MessageActionController.php`.
- App-owned data repositories for contacts, identities, drafts, preferences, saved searches, filters, audit events, and optional metadata cache.
- Optional external connectors for ManageSieve, LDAP, Redis, PostgreSQL, and external IMAP/SMTP providers.

Mailika does not provision mail servers, accept inbound SMTP, control DNS, or store IMAP/SMTP/ManageSieve passwords in the database. The concrete data inventory and retention model are tracked in [Data Handling](DATA_HANDLING.md).

## Threat Model, Trust Boundaries, And Assumptions

Important assets and privileges:

- Mailbox credentials in encrypted server-side sessions.
- Mailbox content, message metadata, attachments, contacts, identities, drafts, filters, preferences, and audit events.
- `APP_KEY`, database credentials, Redis DSNs, LDAP bind credentials, deployment secrets, and CI/package publishing tokens.
- Ability to send mail through the authenticated user's SMTP account.
- Ability to mutate mailbox state: flags, folders, deletion, moves, copies, ACLs, and filters.
- Container images, release artifacts, SBOM/provenance attestations, and dependency-update workflows.

Trust boundaries:

- Browser to Mailika HTTP server: all request parameters, form bodies, uploaded files, cookies, headers, and AJAX requests are attacker-controlled until validated.
- Mailika to IMAP/SMTP/ManageSieve/LDAP/Redis/database: external services may be unavailable, misconfigured, hostile in test environments, or controlled by an attacker when host allowlists are too broad.
- Mail server to Mailika rendering layer: email HTML, MIME structure, attachment metadata, sender headers, dates, subjects, `cid:` references, and message IDs are hostile input.
- Mailika app to public web server: only `public/` may be served; source, storage, secrets, vendor code, migrations, and logs must remain outside the public document root.
- App-owned database to runtime: stored contacts, preferences, filters, drafts, identities, and audit events remain untrusted data when rendered back to HTML or used in headers.
- Developer and CI environment to release artifacts: dependencies, GitHub Actions, container builds, generated assets, and publish credentials are privileged supply-chain surfaces.

Operator-controlled inputs include environment variables, `.env`, web-server configuration, reverse-proxy trusted IPs, Docker/Compose manifests, host allowlists, TLS settings, database DSNs, Redis DSNs, LDAP settings, Sieve settings, and upload limits.

Developer-controlled inputs include source changes, migrations, Composer/npm dependencies, workflows, generated frontend assets, tests, release notes, changelog entries, and Dockerfiles.

Security assumptions:

- Production uses HTTPS, HSTS, secure cookies, `APP_ENV=production`, `APP_DEBUG=false`, `MAILIKA_REQUIRE_TLS=true`, restricted host allowlists, and a strong `APP_KEY`.
- Server-side sessions and rate limits use Redis in multi-node deployments.
- App-owned database storage never contains mailbox passwords.
- Email content is never trusted merely because it came from an authenticated mailbox.
- Fixture adapters and disposable test services are never used in production.

## Attack Surface, Mitigations, And Attacker Stories

Browser-facing attacks:

- CSRF against message actions, contacts, identities, drafts, filters, folders, settings, and account profiles. Mitigation: `CsrfTokenManager` is used by POST controllers and release gates require CSRF preservation.
- Session fixation or credential theft. Mitigation: session IDs rotate after login, credentials are sealed with Sodium using `APP_KEY`, cookies are HttpOnly/SameSite, secure cookies are required in production, and logout clears the vault.
- Response/header injection through subjects, filenames, MIME types, message IDs, identities, Reply-To, or recipients. Mitigation: `Validator::headerText`, address normalization, attachment filename/content-type sanitization, and response header guards.
- Authorization boundary failures between mailbox identities. Mitigation: repositories scope records by mailbox identity, and account profiles store only non-secret metadata.

Email rendering and MIME attacks:

- Stored or reflected XSS through HTML email, inline events, forms, SVG/XML/HTML attachments, remote images, and unsafe CSS. Mitigation: `HtmlSanitizer`, remote-image blocking by default, safe `cid:` rewriting, sandboxed attachment CSP, and active attachment formats forced to `application/octet-stream`.
- Resource abuse through large folders, large MIME messages, or oversized attachments. Mitigation: bounded pagination, metadata-cache limits, upload/download size policies, and the primary IMAP adapter avoiding materialization of server-reported oversized attachment content.
- Private-key leakage through drafts or outgoing mail. Mitigation: OpenPGP private-key armor is blocked in compose bodies and validated attachment streams. Full OpenPGP encryption/signing is explicitly deferred.

Mail transport and connector attacks:

- SSRF-style connections through user-supplied IMAP/SMTP/Sieve/LDAP hosts. Mitigation: production preflight requires restricted host allowlists and valid TCP ports; runtime login and connector code enforce allowed hosts and TLS policy.
- Credential exposure to plaintext or attacker-controlled services. Mitigation: production requires TLS-valid IMAP/SMTP, Sieve/LDAP TLS when enabled, and fixture adapters are rejected by production preflight.
- SMTP abuse through forged identities or header injection. Mitigation: compose may only use authenticated mailbox address or stored identities for that mailbox; recipients and identity sender values are normalized and validated before delivery.
- ManageSieve overwrite risk. Mitigation: publishing is disabled by default, uses active session credentials only, requires host/TLS policy, and refuses to replace server scripts not generated by Mailika.

Operational and supply-chain attacks:

- Public leakage of source, logs, `.env`, storage, Composer/npm metadata, or generated secrets. Mitigation: public document root restrictions, `.htaccess`, sample Nginx/Caddy deny rules, public-path tests, `.gitignore`, and Docker packaging.
- Broken upgrades or unsafe rollback. Mitigation: migrations, `bin/install`, `bin/upgrade`, preflight checks, backup/restore docs, release runbook, and ASVS gate.
- Dependency or CI compromise. Mitigation: Composer/npm audits, CodeQL, dependency review, secret scanning, license check, Trivy container scan, pinned workflow permissions, CODEOWNERS, Dependabot metadata, SBOM, and provenance.

Out-of-scope attacker stories:

- Compromise of the user's actual IMAP/SMTP provider outside Mailika.
- Mail server DNS/MX/SPF/DKIM/DMARC administration errors.
- Browser or OS compromise on the user's device.
- Malicious server operators with direct access to production memory, logs, database, filesystem, or secret manager.
- Full cryptographic guarantees for OpenPGP before a key-management design exists.

## Severity Calibration

Critical:

- Remote code execution in production request handling, dependency loading, template rendering, upload parsing, or container entrypoints.
- Exposure or persistent storage of mailbox passwords, `APP_KEY`, database credentials, LDAP bind credentials, Redis credentials, package publishing tokens, or OpenPGP private-key passphrases.
- Authentication bypass that lets an unauthenticated attacker read mail, send mail, download attachments, or mutate mailbox state for arbitrary users.
- XSS or HTML-email rendering bypass that can exfiltrate mailbox credentials, session cookies, decrypted secrets, or perform authenticated mailbox actions.

High:

- CSRF or authorization bugs that mutate messages, identities, filters, contacts, drafts, settings, folder ACLs, or account profiles for an authenticated victim.
- SSRF or host-policy bypass that sends mailbox credentials to unapproved IMAP/SMTP/Sieve/LDAP hosts.
- SMTP header injection or identity validation failure that enables hidden recipients, sender spoofing, or message tampering.
- Public web-server exposure of `.env`, storage, logs, source, vendor dependencies, migrations, or generated secrets.
- Dependency, workflow, or container publishing compromise that can alter release artifacts or GHCR images.

Medium:

- Remote image blocking bypass that leaks user IP, user agent, or message-read timing without code execution.
- Attachment content-type or disposition weakness that increases phishing or download risk without script execution.
- Rate-limit bypass that enables mailbox-login brute force but does not directly expose credentials.
- Readiness, metrics, logs, or audit events leaking low-sensitivity metadata such as mailbox addresses, counts, or hostnames without secrets or message bodies.
- Denial of service through large folders, expensive search criteria, malformed MIME, or large attachments within configured limits.

Low:

- UI-only inconsistencies, missing empty states, non-sensitive localization/accessibility regressions, or documentation gaps that do not affect secrets, mailbox state, or release integrity.
- Development-only fixture weaknesses when the fixture adapter and disposable services remain blocked by production preflight.
- Missing support for later-roadmap features such as full OpenPGP, CalDAV/CardDAV, calendars, tasks, or full Sieve round-trip editing, unless documentation falsely claims they are complete.

Severity can increase when multiple issues chain across trust boundaries, such as a stored XSS primitive plus a credential/session exposure path, or a host allowlist bypass plus plaintext SMTP/IMAP credentials.

# Compatibility Matrix

Mailika v1 targets standards-based IMAP and SMTP providers. Compatibility evidence must distinguish automated fixtures from real providers and must not include mailbox passwords, private message content, or provider-specific secrets.

## Required Server Capabilities

| Area | Required | Notes |
| --- | --- | --- |
| IMAP | IMAP4rev1 over TLS | Production deployments should use TLS-valid IMAP on port `993` or equivalent. |
| SMTP | Submission with STARTTLS or SMTPS | Production rejects plaintext SMTP when `MAILIKA_REQUIRE_TLS=true`. |
| Authentication | Username/password for IMAP and SMTP | OAuth/provider-token flows are not part of v1. |
| MIME | RFC-compatible multipart mail | HTML is sanitized, remote images are blocked by default, and attachments are size-limited. |
| SMTPUTF8 | Optional | Unicode local-parts are flagged on the send envelope and require server support. |
| IDNA | Supported | Internationalized domains are normalized before storage or delivery. |
| MOVE | Optional | Provider evidence should record whether the server advertises or supports move semantics. |
| ACL | Optional | Mailika reads and enforces IMAP ACL rights when advertised. |
| QUOTA | Optional | Mailika displays quota when available. |
| THREAD/SORT | Optional | Threaded listing can use available metadata and local grouping. |
| ManageSieve | Optional | Publishing is disabled unless explicitly configured with TLS and host allowlists. |
| LDAP | Optional | Directory lookup is read-only and disabled unless explicitly configured. |

## Automated Fixtures

| Environment | Coverage | Status | Evidence |
| --- | --- | --- | --- |
| Fixture mailbox adapter | Browser and deterministic local workflows | Covered by unit/integration/e2e tests | `MAILIKA_IMAP_ADAPTER=fixture` |
| GreenMail | External IMAP/SMTP login, send, read, attachments, flags, folders, move/delete | Optional CI/local fixture | `composer fixtures:check -- mail` then `composer test:external-mail` |
| Dovecot/Pigeonhole fixture | ManageSieve publishing flow | Optional CI/local fixture | `composer fixtures:check -- sieve` then `composer test:external-sieve` |
| PHP ManageSieve protocol fixture | ManageSieve publish protocol flow without Docker | Optional local fixture | `php tests/fixtures/managesieve/server.php --host=127.0.0.1 --port=4190` then `composer test:external-sieve` |

## Real Provider Matrix

Add real-provider results only after testing with a disposable mailbox and no sensitive data.

| Provider | Product/Version | IMAP TLS | SMTP TLS | Login | Folders | Search | Read HTML/Text | Attachments | Send | Drafts | Move/Delete | ACL/Quota | Sieve | Result | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Not yet recorded | Use disposable provider mailbox only | Pending provider-specific evidence | Pending provider-specific evidence | Pending | Pending | Pending | Pending | Pending | Pending | Pending | Pending | Pending | Pending | Pending | Add release evidence link after testing |

## Provider Test Checklist

1. Use a disposable mailbox with no private data.
2. Configure restricted `MAILIKA_ALLOWED_IMAP_HOSTS` and `MAILIKA_ALLOWED_SMTP_HOSTS`.
3. Keep `MAILIKA_REQUIRE_TLS=true`.
4. Verify login, folder listing, message search, plain/HTML read, remote image blocking, inline images, attachments, compose/send, reply/reply-all/forward, drafts, move/copy/delete/archive, contacts autocomplete, settings, and logout.
5. Record server capabilities such as `MOVE`, `SORT`, `THREAD`, `ACL`, `QUOTA`, `IDLE`, and `SMTPUTF8` when visible.
6. For ManageSieve, use a disposable script name and verify Mailika refuses to overwrite externally edited scripts.
7. Attach logs only after redacting email addresses, hostnames if needed, message IDs, IP addresses, and all secrets.

## Known Compatibility Boundaries

- Mailika does not provision mailboxes, administer DNS, run an MTA, or configure provider-specific OAuth in v1.
- The fixture mailbox adapter is for tests and demos only; production preflight rejects it.
- The legacy `ext-imap` adapter is optional fallback and does not provide full attachment download parity.
- OpenPGP encryption/signing, CalDAV/CardDAV, calendar, and tasks remain later-roadmap work.

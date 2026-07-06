# Architecture

Mailika is a frameworkless PHP webmail client. The HTTP entrypoint is `public/index.php`, which boots configuration, sessions, security headers, routing, controllers, and templates.

## Modules

- `Auth` manages mailbox credentials, encrypted session storage, and login/logout.
- `Mail` defines IMAP/SMTP contracts and concrete adapters.
- `Security` owns CSRF, headers, credential sealing, HTML sanitization, and rate limiting.
- `Database`, `Contact`, and `Preferences` store only Mailika-owned data.
- `Audit` records security-relevant events without secrets.

## Mail Boundary

Mailika v1 connects to existing IMAP/SMTP services. It does not create mailboxes, accept inbound SMTP, configure DNS, or operate a mail server stack.

The built-in `PhpImapMailboxClient` requires the PHP IMAP extension in production. Its absence is reported as a controlled application error, not a fatal runtime failure.

## Credential Boundary

Mailbox credentials are encrypted with `APP_KEY` before session storage. They are not written to the database, logs, audit events, cache, or templates.

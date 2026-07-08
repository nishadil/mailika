# Support

Mailika is an open-source project. Community support happens through GitHub issues and discussions when enabled for the repository.

## Before Opening An Issue

- Search existing issues first.
- Confirm the problem is reproducible on the latest `development` branch or the latest release.
- Include PHP version, database, web server, browser, deployment type, and relevant Mailika configuration without secrets.
- For provider-specific mail behavior, use the provider compatibility issue template and follow [Compatibility Matrix](docs/COMPATIBILITY.md).
- Do not include mailbox passwords, `APP_KEY`, database passwords, Redis DSNs, LDAP bind passwords, private keys, or private message content.

## Security Reports

Do not open public issues for suspected vulnerabilities. Email security reports to <opensource@nishadil.dev> and include affected version or commit, reproduction steps, impact, and attacker prerequisites.

Good-faith security research is covered in [Security Policy](SECURITY.md). Public issues that contain vulnerability details may be closed or redacted while triage happens privately.

## Scope

Mailika v1 is a self-hosted webmail client for existing IMAP/SMTP servers. Mail-server provisioning, DNS setup, MTA administration, CalDAV/CardDAV, calendar, tasks, and full OpenPGP encryption/signing are outside the current support scope unless they are explicitly listed in a future release.

# Contributing

Mailika is an open-source project under GPL-3.0-only. Contributions should keep the project frameworkless, auditable, and production-oriented.

## Development Rules

- Use PHP 8.3+ with `declare(strict_types=1);`.
- Prefer small, reviewed Composer packages over large frameworks.
- Treat all email content and attachment metadata as hostile input.
- Do not persist mailbox passwords or provider tokens.
- Keep internal module boundaries clean; do not add a public plugin API before v1 core behavior is stable.
- Add focused tests for security-sensitive behavior.
- Preserve keyboard access, visible focus, mobile layout, RTL direction, and reduced-motion behavior for UI changes.
- Check security-sensitive changes against [Threat Model](docs/THREAT_MODEL.md), check data-store/log/metric/cache/export changes against [Data Handling](docs/DATA_HANDLING.md), check release-sensitive changes against [ASVS Release Gate](docs/ASVS_RELEASE_GATE.md), and update [Changelog](CHANGELOG.md) when behavior, security posture, operations, or user workflows change.

## Checks

Run these before opening a pull request:

```bash
composer check
npm run check
```

Pull requests should describe security impact, operational impact, and any migration or rollback notes. Release-sensitive changes should also be checked against [Release Runbook](docs/RELEASE.md).

For support requests and issue scope, see [Support](SUPPORT.md). Suspected vulnerabilities must be reported privately using [Security Policy](SECURITY.md).

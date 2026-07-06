# Contributing

Mailika is an open-source project under GPL-3.0-only. Contributions should keep the project frameworkless, auditable, and production-oriented.

## Development Rules

- Use PHP 8.3+ with `declare(strict_types=1);`.
- Prefer small, reviewed Composer packages over large frameworks.
- Treat all email content and attachment metadata as hostile input.
- Do not persist mailbox passwords or provider tokens.
- Keep internal module boundaries clean; do not add a public plugin API before v1 core behavior is stable.
- Add focused tests for security-sensitive behavior.

## Checks

Run these before opening a pull request:

```bash
composer check
npm run typecheck
npm run lint
npm run build
```

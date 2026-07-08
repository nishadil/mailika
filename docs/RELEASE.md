# Release Runbook

Use this runbook for every Mailika release candidate. A release is not ready until the ASVS gate is satisfied, release evidence is attached, and rollback notes are clear.

## 1. Confirm Scope

- Release scope remains webmail for existing IMAP/SMTP servers.
- Later-roadmap items such as full OpenPGP, CalDAV/CardDAV, calendar, and task features are not represented as complete.
- Review [Threat Model](THREAT_MODEL.md) and confirm assets, trust boundaries, assumptions, and severity calibration still match the release.
- Review [Data Handling](DATA_HANDLING.md) and confirm persisted fields, logs, metrics, audit events, exports, caches, uploads, and backups still match the data inventory.
- Review [Accessibility](ACCESSIBILITY.md) and confirm automated axe, keyboard, focus, mobile, RTL, theme, and reduced-motion checks still pass for release-critical flows.
- Review [ASVS Release Gate](ASVS_RELEASE_GATE.md) and record any accepted residual risk with an owner and follow-up issue.

## 2. Prepare Evidence

Update [Changelog](../CHANGELOG.md) so the release has clear user-facing notes, security notes, migration notes, and intentionally deferred items.

Generate a release evidence skeleton:

```bash
composer release-evidence -- vX.Y.Z > release-evidence-vX.Y.Z.md
```

After filling every evidence row, checking every applicable checklist item, and replacing all placeholders, validate the final evidence note:

```bash
composer validate-release-evidence -- release-evidence-vX.Y.Z.md
```

Create a release evidence note with:

| Evidence | Required Value |
| --- | --- |
| Release tag | `vX.Y.Z` |
| Commit SHA | Full Git commit SHA |
| CI run | Link to passing CI run on `main` |
| CodeQL | Link to passing CodeQL run |
| Dependency review | Link to passing pull request check, when applicable |
| Container scan | Link or attach Trivy result for the release image |
| SBOM/provenance | GHCR attestation references |
| Migration notes | Migrations included and rollback compatibility |
| Threat model | Review date and any updates |
| Data handling | Review date and any updates |
| Accessibility | Review date and any updates |
| Changelog | Link to reviewed changelog section |
| Compatibility matrix | Link to updated compatibility notes |
| Backup/restore | Date, operator, and result of restore validation |
| External mail fixtures | GreenMail or real-provider compatibility result |
| External Sieve fixtures | ManageSieve compatibility result, when enabled |
| Residual risks | Owner and follow-up issue, or `None` |

## 3. Run Local Gates

```bash
composer check
npm run check
docker compose config --quiet
```

`composer check` includes local workflow hardening checks for explicit GitHub Actions permissions, concurrency, job timeouts, versioned action references, rejected `pull_request_target` usage, and the required release-gate inventory in CI. It also checks release metadata, support/security contacts, repository links, license declarations, generated asset handling, and public document-root invariants.

Optional external fixtures:

```bash
docker compose --profile mail-fixtures up -d greenmail
composer fixtures:check -- mail
composer test:external-mail
docker compose --profile mail-fixtures down

docker compose --profile sieve-fixtures up -d managesieve
composer fixtures:check -- sieve
composer test:external-sieve
docker compose --profile sieve-fixtures down
```

For IMAP/SMTP, the readiness check and test may also target a disposable non-Compose GreenMail-compatible fixture that is already listening on `MAILIKA_TEST_MAIL_HOST`, `MAILIKA_TEST_SMTP_PORT`, and `MAILIKA_TEST_IMAP_PORT`.

For ManageSieve, the readiness check and test may target either the Dovecot/Pigeonhole Compose fixture or the disposable PHP protocol fixture in `tests/fixtures/managesieve/server.php`. Mark release evidence clearly when the PHP protocol fixture was used instead of Dovecot/Pigeonhole.

Update [Compatibility Matrix](COMPATIBILITY.md) with fixture or provider results.

## 4. Exercise Upgrade And Restore

1. Create a PostgreSQL backup using [Backup And Restore](BACKUP_RESTORE.md).
2. Deploy the release candidate image or checkout.
3. Run `php bin/upgrade`.
4. Verify `/readyz`, `/metrics`, and a real mailbox login.
5. Restore the backup into a disposable database and run `php bin/upgrade` against the restored schema.

Record the backup filename, restore target, and verification result in the release evidence note.

## 5. Tag And Publish

1. Confirm `main` is clean and points at the reviewed commit.
2. Confirm the `CHANGELOG.md` section for the release no longer says `Unreleased`.
3. Create a signed tag named `vX.Y.Z` when signing is available.
4. Push the tag.
5. Wait for the publish workflow to build, scan, push to GHCR, and emit SBOM/provenance attestations.
6. Verify the expected GHCR tags: `vX.Y.Z`, `X.Y`, `latest` for default-branch releases, and `sha-*`.

## 6. Post-Release Checks

- Pull the published image from `ghcr.io/nishadil/mailika`.
- Start with a production-like environment and restricted host allowlists.
- Run `php bin/upgrade`.
- Verify `/healthz`, `/readyz`, `/metrics`, login, folder listing, message read, compose/send, attachment download, settings, contacts, filters, and logout.
- Attach the final evidence note to the GitHub release.

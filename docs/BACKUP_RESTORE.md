# Backup And Restore

Mailika stores only app-owned data: contacts, contact groups, identities, preferences, saved searches, local Sieve rules, non-secret account profiles, audit events, drafts, and optional mailbox metadata cache. Mailika does not store IMAP, SMTP, or ManageSieve passwords in the database. See [Data Handling](DATA_HANDLING.md) for the complete data inventory and retention model.

## What To Back Up

- Production database configured by `DB_DSN`, preferably PostgreSQL.
- `.env` or secret-manager entries, especially `APP_KEY`, database credentials, LDAP bind credentials, and host allowlists.
- Deployment manifests and reverse-proxy configuration.
- Optional structured logs if your retention policy requires them.

Do not back up `storage/cache` as durable state. Server-side sessions are intentionally short-lived; after restore, users may need to sign in again.

## PostgreSQL Backup

Create a timestamped custom-format dump:

```bash
mkdir -p backups
docker compose exec -T postgres pg_dump \
  -U mailika \
  -d mailika \
  --format=custom \
  --file=- > "backups/mailika-$(date -u +%Y%m%dT%H%M%SZ).dump"
```

For non-Docker deployments, run the same `pg_dump` command from a host that can reach PostgreSQL and use credentials from your secret manager.

## PostgreSQL Restore

Restore into an empty or replaceable database, then run migrations for the deployed Mailika version:

```bash
docker compose exec -T postgres pg_restore \
  -U mailika \
  -d mailika \
  --clean \
  --if-exists < backups/mailika-YYYYMMDDTHHMMSSZ.dump

docker compose exec app php bin/upgrade
curl -fsS http://127.0.0.1:8080/readyz
```

If the restored `.env` has a different `APP_KEY`, old server-side sessions cannot be decrypted and users must sign in again. Database records remain usable because mailbox passwords are not persisted there.

## Upgrade Safety

Before deploying a release:

1. Take a database backup.
2. Record the image tag or commit being replaced.
3. Deploy the new image or checkout.
4. Run `php bin/upgrade`.
5. Verify `/readyz`, `/metrics`, and a real mailbox login.

If rollback is required, redeploy the previous image and restore the matching backup when migrations are not backward-compatible.

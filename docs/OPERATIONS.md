# Operations

Mailika can run behind Nginx, Caddy, Apache, or any reverse proxy that forwards requests to PHP-FPM.

## Health

`GET /healthz` returns JSON with basic application status. It does not validate user mailbox connectivity and does not expose secrets.

## Upgrades

1. Back up the database.
2. Deploy the new image or release artifact.
3. Run database migrations.
4. Warm caches if configured.
5. Verify `/healthz` and a test mailbox login.

## Containers

The default image is intended for GHCR as `ghcr.io/nishadil/mailika`. Runtime containers should run as a non-root user and mount persistent storage for sessions, cache, logs, and uploads.

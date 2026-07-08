## Summary

- 

## Security Impact

- [ ] No mailbox credentials, provider tokens, private keys, or passwords are persisted or logged.
- [ ] Email HTML, attachment names, MIME types, URLs, and user input remain treated as hostile input.
- [ ] CSRF, authorization scoping, host allowlists, and TLS requirements are preserved where relevant.
- [ ] Changes do not expose source, config, storage, logs, vendor files, or generated secrets under `public/`.

## Operational Impact

- [ ] Database migrations are included or explicitly not needed.
- [ ] Install/upgrade/preflight behavior is considered.
- [ ] Data handling docs are updated for new persisted fields, logs, metrics, exports, imports, caches, uploads, or backups.
- [ ] Accessibility, keyboard, RTL/localization, and reduced-motion behavior are preserved where UI changed.
- [ ] Backup/restore, Redis, web-server, container, or environment-variable docs are updated if behavior changed.
- [ ] `CHANGELOG.md` is updated for release-impacting changes.
- [ ] Generated `public/build/` assets are build output only.

## Verification

- [ ] `composer check`
- [ ] `npm run check`
- [ ] Additional integration/security/performance tests:

## Release Gate

- [ ] The change does not weaken [ASVS Release Gate](../docs/ASVS_RELEASE_GATE.md) controls.
- [ ] Release-impacting changes update [Release Runbook](../docs/RELEASE.md) evidence or steps where needed.
- [ ] Any accepted residual risk is documented with an owner and follow-up issue.

# Advanced Mail Roadmap

Mailika v1 focuses on core webmail. The following work is intentionally staged after the mailbox, compose, contacts, and production runtime foundations are stable:

- Broader Sieve editor support for full external-script import and round-trip editing. Constrained redirect and vacation actions with days, subject, sender exclusions, and recipient aliases are already available in local rules. Mailika-generated scripts can be imported back into local rules, and pasted external scripts can be inspected for required extensions, actions, ownership, and unsupported constructs.
- OpenPGP encryption/signing after a dedicated key-management design; private-key leak blocking is already active in compose.
- Public module API after internal modules stop changing rapidly.
- Multi-account aggregation using non-secret account profiles without persisting mailbox passwords by default. Saved profiles are already available from the mailbox sidebar and prefill a fresh sign-in without storing passwords.
- IMAP MYRIGHTS/GETACL visibility and admin-gated SETACL/DELETEACL updates are in place when the server advertises ACL. Richer shared-folder workflows remain later work.
- Broader message metadata cache invalidation from server state. Local read/unread, flag, move, copy, archive, and delete actions already update a bounded envelope cache.
- CalDAV/CardDAV/calendar/tasks only after webmail v1 is reliable.

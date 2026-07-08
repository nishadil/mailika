# OpenPGP Planning Spike

OpenPGP encryption and signing are deferred until Mailika has a dedicated key-management design. The current implementation includes only a defensive private-key leak guard for compose bodies and attachments.

Current defensive baseline:

- Compose rejects OpenPGP private-key armor in message bodies.
- Compose rejects OpenPGP private-key armor in validated attachments, scanning the full file stream with boundary overlap.
- Public-key armor is allowed so users can still exchange public keys manually.
- No OpenPGP private keys are persisted by Mailika.

Release gate for encryption/signing:

- Browser-side versus server-side cryptographic operations, including the trust boundary for decrypted plaintext and private-key use.
- Private-key storage model, passphrase handling, unlock lifetime, backup, revocation, and recovery boundaries.
- PGP/MIME support before inline PGP, with MIME canonicalization and attachment encryption/decryption tests.
- Recipient key discovery model, trust prompts, fingerprint display, key rotation, and stale-key warnings.
- Message signing semantics, signature verification UI, and downgrade/failure states.
- Compatibility tests with Thunderbird, Apple Mail, Outlook, Roundcube Enigma, and common mobile clients.
- Security review for XSS-to-key-exfiltration risks before any private-key import or unlock flow ships.

Non-goals for v1:

- Automatic key-server publishing.
- Server-side unattended decryption.
- Persisting mailbox passwords or OpenPGP private-key passphrases.

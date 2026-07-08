# Sieve Roadmap

Mailika now includes local Sieve rule management for a constrained, auditable rule subset, a generated script preview, safe import for Mailika-generated scripts, and local inspection for pasted existing scripts. Rules are stored per mailbox in the Mailika data store or session fallback. Optional ManageSieve publishing can upload and activate the generated script when an operator configures a TLS-valid ManageSieve endpoint. Publishing refuses to overwrite an existing server script unless the script is absent or already contains Mailika's generated ownership marker.

Remaining server publishing decisions:

- Full import and round-trip editing for complex existing server scripts. Local inspection, unsupported-construct detection, and Mailika-generated script re-import are already available.
- Advanced vacation controls beyond days, subject, sender exclusions, and recipient aliases.
- Broader external-server compatibility coverage for ManageSieve implementations beyond the bundled disposable Dovecot/Pigeonhole fixture.

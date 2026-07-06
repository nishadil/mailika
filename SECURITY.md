# Security Policy

Mailika accepts responsible vulnerability reports at <opensource@nishadil.dev>.

Please include:

- Affected version or commit.
- Reproduction steps.
- Impact and attacker prerequisites.
- Any proof-of-concept details needed to validate safely.

Do not open public issues for suspected vulnerabilities until the issue has been triaged.

## Baseline

Mailika targets OWASP ASVS 5.0 Level 2 for the web application and applies stricter controls around session-only mailbox credentials, HTML email rendering, remote content, TLS validation, and dependency hygiene.

## Supported Versions

Until the first stable release, only the default branch receives security fixes.

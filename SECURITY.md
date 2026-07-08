# Security Policy

Mailika accepts responsible vulnerability reports at <opensource@nishadil.dev>.

Please include:

- Affected version or commit.
- Reproduction steps.
- Impact and attacker prerequisites.
- Any proof-of-concept details needed to validate safely.

Do not open public issues for suspected vulnerabilities until the issue has been triaged.

For non-security support requests, use [Support](SUPPORT.md) and avoid sharing secrets or private mailbox content.

## Baseline

Mailika targets OWASP ASVS 5.0 Level 2 for the web application and applies stricter controls around session-only mailbox credentials, HTML email rendering, remote content, TLS validation, and dependency hygiene.

Use [ASVS Release Gate](docs/ASVS_RELEASE_GATE.md) before tagging a release candidate.

## Handling Process

Security reports should receive an initial maintainer response within 7 days. The maintainer will confirm scope, request missing reproduction detail when needed, assess severity, and decide whether the issue affects released versions, unreleased development code, or deployment guidance.

Validated vulnerabilities should be fixed privately when practical, covered by regression tests, and released with a clear advisory or changelog entry. Public disclosure should wait until a fix or mitigation is available, unless active exploitation or ecosystem risk requires faster disclosure.

## Safe Harbor

Good-faith research is welcome when it avoids privacy violations, service disruption, data destruction, spam, credential harvesting, persistence, or access to third-party mailboxes. Testing should use accounts and systems you own or are explicitly authorized to test.

## Supported Versions

Until the first stable release, only the default branch receives security fixes.

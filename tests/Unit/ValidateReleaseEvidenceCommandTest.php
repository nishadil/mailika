<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ValidateReleaseEvidenceCommandTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testAcceptsCompletedReleaseEvidence(): void
    {
        $result = $this->validate($this->completedEvidence());

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Release evidence validation passed.', $result['stdout']);
    }

    public function testRejectsTodoPlaceholdersAndUncheckedItems(): void
    {
        $evidence = str_replace(
            ['Trivy passed for ghcr.io/nishadil/mailika:v1.2.3', '- [x] `composer check`'],
            ['TODO', '- [ ] `composer check`'],
            $this->completedEvidence(),
        );

        $result = $this->validate($evidence);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('must not contain TODO placeholders', $result['stderr']);
        self::assertStringContainsString('must not contain unchecked checklist items', $result['stderr']);
    }

    public function testRejectsBareNotApplicableEvidence(): void
    {
        $evidence = str_replace(
            '| Dependency review | N/A: release tag was validated outside a pull request |',
            '| Dependency review | N/A |',
            $this->completedEvidence(),
        );

        $result = $this->validate($evidence);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('"Dependency review" N/A evidence must include a reason', $result['stderr']);
    }

    public function testRejectsResidualRisksWithoutOwner(): void
    {
        $evidence = str_replace(
            '| Residual risks | None |',
            '| Residual risks | Mailbox provider behavior needs follow-up |',
            $this->completedEvidence(),
        );

        $result = $this->validate($evidence);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('residual risks must be "None" or include an owner', $result['stderr']);
    }

    public function testRejectsSecretLikeEvidence(): void
    {
        $privateKeyMarker = '-----BEGIN ' . 'OPENSSH ' . 'PRIVATE KEY-----';
        $evidence = str_replace(
            '| Migration notes | database/migrations applied with php bin/upgrade |',
            '| Migration notes | ' . $privateKeyMarker . ' |',
            $this->completedEvidence(),
        );

        $result = $this->validate($evidence);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('potential secret pattern found: private-key-block', $result['stderr']);
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function validate(string $evidence): array
    {
        $path = tempnam(sys_get_temp_dir(), 'mailika-release-evidence-');
        self::assertIsString($path);
        file_put_contents($path, $evidence);

        try {
            return $this->runValidator($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runValidator(string $path): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->root . '/bin/validate-release-evidence', $path],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->root,
        );

        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'code' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function completedEvidence(): string
    {
        return <<<'MARKDOWN'
# Mailika Release Evidence

| Field | Value |
| --- | --- |
| Release tag | v1.2.3 |
| Commit SHA | 0123456789abcdef0123456789abcdef01234567 |
| Branch | main |
| Generated at | 2026-07-08T07:30:00Z |

## Required Evidence

| Evidence | Link Or Result |
| --- | --- |
| CI run on `main` | https://github.com/nishadil/mailika/actions/runs/1 |
| CodeQL run | https://github.com/nishadil/mailika/actions/runs/2 |
| Dependency review | N/A: release tag was validated outside a pull request |
| Container scan | Trivy passed for ghcr.io/nishadil/mailika:v1.2.3 |
| SBOM/provenance | GHCR attestations verified |
| Migration notes | database/migrations applied with php bin/upgrade |
| Threat model review | docs/THREAT_MODEL.md reviewed on 2026-07-08 |
| Data handling review | docs/DATA_HANDLING.md reviewed on 2026-07-08 |
| Accessibility review | npm run check passed with axe coverage |
| Changelog section | CHANGELOG.md v1.2.3 reviewed |
| Compatibility matrix | docs/COMPATIBILITY.md updated |
| Backup/restore validation | restore tested against disposable database |
| External mail fixture or provider compatibility | GreenMail fixture passed |
| External Sieve compatibility | N/A: ManageSieve publishing disabled for this release |
| Residual risks | None |

## Local Gates

- [x] `composer check`
- [x] `npm run check`
- [x] `docker compose config --quiet`

## Upgrade And Restore

- [x] Backup created before upgrade.
- [x] `php bin/upgrade` passed on release candidate.

## Release Notes

- User-facing changes:
  - Core webmail release.
- Security changes:
  - Release gates passed.
- Migration or operator notes:
  - Run php bin/upgrade.
- Deferred or known limitations:
  - Calendar remains later roadmap.

MARKDOWN;
    }
}

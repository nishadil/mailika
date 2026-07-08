<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ExternalFixtureReadinessCommandTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testHelpShowsUsage(): void
    {
        $result = $this->runCommand('--help');

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Usage: php bin/check-external-fixtures [mail|sieve|all]', $result['stdout']);
    }

    public function testRejectsUnknownFixtureTarget(): void
    {
        $result = $this->runCommand('calendar');

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('Unknown fixture target: calendar', $result['stderr']);
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runCommand(string $target): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->root . '/bin/check-external-fixtures', $target],
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
}

<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Mail\PhpImapQuotaParser;
use PHPUnit\Framework\TestCase;

final class PhpImapQuotaParserTest extends TestCase
{
    public function testParsesStorageQuotaAndConvertsKilobytesToBytes(): void
    {
        $quota = (new PhpImapQuotaParser())->parse([
            'STORAGE' => [
                'usage' => 1024,
                'limit' => 4096,
            ],
        ]);

        self::assertSame(1_048_576, $quota->usedBytes);
        self::assertSame(4_194_304, $quota->limitBytes);
    }

    public function testParsesNestedQuotaRootResponse(): void
    {
        $quota = (new PhpImapQuotaParser())->parse([
            'ROOT' => [
                'storage' => [
                    'usage' => '2048',
                    'limit' => '8192',
                ],
            ],
        ]);

        self::assertSame(2_097_152, $quota->usedBytes);
        self::assertSame(8_388_608, $quota->limitBytes);
    }

    public function testParsesLegacyUsedLimitShape(): void
    {
        $quota = (new PhpImapQuotaParser())->parse([
            'usage' => 50,
            'limit' => 100,
        ]);

        self::assertSame(51_200, $quota->usedBytes);
        self::assertSame(102_400, $quota->limitBytes);
    }

    public function testReturnsEmptyQuotaForUnknownShape(): void
    {
        $quota = (new PhpImapQuotaParser())->parse(['MESSAGES' => ['usage' => 10, 'limit' => 20]]);

        self::assertNull($quota->usedBytes);
        self::assertNull($quota->limitBytes);
    }
}

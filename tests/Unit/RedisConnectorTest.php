<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Cache\RedisConnector;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RedisConnectorTest extends TestCase
{
    public function testParsesRedisDsnWithPasswordAndDatabase(): void
    {
        $options = (new RedisConnector())->options('redis://:secret@cache.example.com:6380/4?timeout=2.25');

        self::assertSame('cache.example.com', $options->host);
        self::assertSame(6380, $options->port);
        self::assertSame(2.25, $options->timeoutSeconds);
        self::assertSame(4, $options->database);
        self::assertNull($options->username);
        self::assertSame('secret', $options->password);
    }

    public function testParsesTlsDsnWithAclUsername(): void
    {
        $options = (new RedisConnector())->options('rediss://mailika:secret@cache.example.com/2');

        self::assertSame('tls://cache.example.com', $options->host);
        self::assertSame(6379, $options->port);
        self::assertSame(2, $options->database);
        self::assertSame('mailika', $options->username);
        self::assertSame('secret', $options->password);
    }

    public function testParsesPhpRedisSessionDsnDatabaseQuery(): void
    {
        $options = (new RedisConnector())->options('tcp://redis:6379?database=5&timeout=1');

        self::assertSame('redis', $options->host);
        self::assertSame(6379, $options->port);
        self::assertSame(1.0, $options->timeoutSeconds);
        self::assertSame(5, $options->database);
    }

    public function testRejectsInvalidDsnScheme(): void
    {
        $this->expectException(RuntimeException::class);

        (new RedisConnector())->options('http://cache.example.com:6379/0');
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Audit\AuditEventRepositoryInterface;
use Mailika\Audit\AuditLogger;
use Mailika\Config\Config;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase
{
    public function testSanitizesSecretsBeforeDatabaseAuditWrite(): void
    {
        $events = new class implements AuditEventRepositoryInterface {
            /**
             * @var list<array<string, mixed>>
             */
            public array $records = [];

            /**
             * @param array<string, scalar|null> $metadata
             */
            public function record(
                string $eventType,
                ?string $mailboxIdentity,
                ?string $ipAddress,
                ?string $userAgent,
                array $metadata,
            ): void {
                $this->records[] = [
                    'event_type' => $eventType,
                    'mailbox' => $mailboxIdentity,
                    'ip' => $ipAddress,
                    'user_agent' => $userAgent,
                    'metadata' => $metadata,
                ];
            }
        };

        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('c', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . '/mailika-audit-test-' . bin2hex(random_bytes(4)) . '.log';

        $logger = new AuditLogger(Config::fromEnvironment(dirname(__DIR__, 2)), $events);
        $logger->record('login.success', [
            'mailbox' => 'user@example.com',
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'password' => 'secret',
            'token' => 'secret',
        ]);

        self::assertSame('login.success', $events->records[0]['event_type']);
        self::assertSame('user@example.com', $events->records[0]['mailbox']);
        self::assertArrayNotHasKey('password', $events->records[0]['metadata']);
        self::assertArrayNotHasKey('token', $events->records[0]['metadata']);
    }
}

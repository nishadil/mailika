<?php

declare(strict_types=1);

namespace Mailika\Tests\Fixtures;

use Mailika\Audit\AuditEventRepositoryInterface;

final class InMemoryAuditEventRepository implements AuditEventRepositoryInterface
{
    /**
     * @var list<array{
     *     event_type:string,
     *     mailbox:?string,
     *     ip:?string,
     *     user_agent:?string,
     *     metadata:array<string, scalar|null>
     * }>
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
}

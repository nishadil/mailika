<?php

declare(strict_types=1);

namespace Mailika\Audit;

final readonly class NullAuditEventRepository implements AuditEventRepositoryInterface
{
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
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Audit;

use PDO;

final readonly class AuditEventRepository implements AuditEventRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

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
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_events (event_type, mailbox_identity, ip_address, user_agent, metadata)
             VALUES (:event_type, :mailbox_identity, :ip_address, :user_agent, :metadata)',
        );
        $statement->execute([
            'event_type' => $eventType,
            'mailbox_identity' => $mailboxIdentity,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }
}

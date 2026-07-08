<?php

declare(strict_types=1);

namespace Mailika\Tests\Performance;

use Mailika\Mail\MessageEnvelope;
use Mailika\Mail\MessageSearchCriteria;
use Mailika\Mail\SessionMessageMetadataCacheRepository;
use PHPUnit\Framework\TestCase;

final class SearchCriteriaPerformanceTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testLargeFolderPaginationCriteriaIsBounded(): void
    {
        $criteria = new MessageSearchCriteria('INBOX', 'invoice', 20, 50);

        self::assertSame(50, $criteria->limit);
        self::assertSame(20, $criteria->page);
    }

    public function testCachedMetadataHandlesTenThousandMessageFolderWithBoundedPageSize(): void
    {
        $_SESSION = [];
        $repository = new SessionMessageMetadataCacheRepository();
        $messages = [];

        for ($i = 1; $i <= 10_000; $i++) {
            $messages[] = new MessageEnvelope(
                (string) $i,
                'sender' . ($i % 25) . '@example.com',
                'Invoice-' . $i,
                '2026-07-07',
                seen: $i % 2 === 0,
                hasAttachments: false,
            );
        }

        $repository->store('user@example.com', 'INBOX', $messages);

        $page = $repository->list('user@example.com', 'INBOX', 50, 10);
        $search = $repository->list('user@example.com', 'INBOX', 50, 1, 'Invoice-9999');

        self::assertCount(50, $page);
        self::assertSame('9550', $page[0]->id);
        self::assertCount(1, $search);
        self::assertSame('9999', $search[0]->id);
    }
}

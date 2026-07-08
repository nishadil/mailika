<?php

declare(strict_types=1);

namespace Mailika\Filter;

use Mailika\Auth\MailboxCredentials;

final readonly class DisabledSievePublisher implements SievePublisherInterface
{
    public function configured(): bool
    {
        return false;
    }

    public function scriptName(): string
    {
        return 'mailika';
    }

    public function publish(MailboxCredentials $credentials, string $script): SievePublishResult
    {
        throw new SievePublishException('ManageSieve publishing is not configured.');
    }
}

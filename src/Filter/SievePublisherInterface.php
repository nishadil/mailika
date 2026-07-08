<?php

declare(strict_types=1);

namespace Mailika\Filter;

use Mailika\Auth\MailboxCredentials;

interface SievePublisherInterface
{
    public function configured(): bool;

    public function scriptName(): string;

    public function publish(MailboxCredentials $credentials, string $script): SievePublishResult;
}

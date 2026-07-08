<?php

declare(strict_types=1);

namespace Mailika\Tests\Fixtures;

use Mailika\Auth\MailboxCredentials;
use Mailika\Filter\SievePublishException;
use Mailika\Filter\SievePublishResult;
use Mailika\Filter\SievePublisherInterface;

final class FakeSievePublisher implements SievePublisherInterface
{
    public ?string $publishedScript = null;

    public function __construct(
        private readonly bool $configured = true,
        private readonly bool $fail = false,
    ) {
    }

    public function configured(): bool
    {
        return $this->configured;
    }

    public function scriptName(): string
    {
        return 'mailika-test';
    }

    public function publish(MailboxCredentials $credentials, string $script): SievePublishResult
    {
        if ($this->fail) {
            throw new SievePublishException('Simulated ManageSieve failure.');
        }

        $this->publishedScript = $script;

        return new SievePublishResult(true, 'Filters published to ManageSieve.');
    }
}

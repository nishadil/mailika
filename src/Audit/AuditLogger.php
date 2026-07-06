<?php

declare(strict_types=1);

namespace Mailika\Audit;

use Mailika\Config\Config;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

final readonly class AuditLogger
{
    private Logger $logger;

    public function __construct(Config $config)
    {
        $this->logger = new Logger('audit');
        $this->logger->pushHandler(new StreamHandler($config->string('log.path'), Level::Info));
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function record(string $event, array $context = []): void
    {
        unset($context['password'], $context['token'], $context['secret']);
        $this->logger->info($event, $context);
    }
}

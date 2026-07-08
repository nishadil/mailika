<?php

declare(strict_types=1);

namespace Mailika\Audit;

use Mailika\Config\Config;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Throwable;

final readonly class AuditLogger
{
    private Logger $logger;

    public function __construct(
        Config $config,
        private AuditEventRepositoryInterface $events,
    ) {
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

        try {
            $this->events->record(
                $event,
                $this->contextString($context, 'mailbox'),
                $this->contextString($context, 'ip'),
                $this->contextString($context, 'user_agent'),
                $context,
            );
        } catch (Throwable) {
            $this->logger->warning('audit.database_write_failed', ['event' => $event]);
        }
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function contextString(array $context, string $key): ?string
    {
        $value = $context[$key] ?? null;
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}

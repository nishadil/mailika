<?php

declare(strict_types=1);

namespace Mailika\Tests\Fixtures;

use Mailika\Filter\ManageSieveConnectionInterface;
use Mailika\Filter\SievePublishException;

final class FakeManageSieveConnection implements ManageSieveConnectionInterface
{
    /** @var list<string> */
    public array $writes = [];

    public bool $tlsEnabled = false;

    public bool $closed = false;

    /**
     * @param list<string> $lines
     * @param list<string> $literals
     */
    public function __construct(private array $lines, private array $literals = [])
    {
    }

    public function readLine(): string
    {
        $line = array_shift($this->lines);
        if ($line === null) {
            throw new SievePublishException('No fake ManageSieve response line is available.');
        }

        return $line;
    }

    public function readBytes(int $bytes): string
    {
        $literal = array_shift($this->literals);
        if ($literal === null) {
            return str_repeat('x', $bytes);
        }

        if (strlen($literal) !== $bytes) {
            throw new SievePublishException('Fake ManageSieve literal length mismatch.');
        }

        return $literal;
    }

    public function write(string $bytes): void
    {
        $this->writes[] = $bytes;
    }

    public function enableTls(): void
    {
        $this->tlsEnabled = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

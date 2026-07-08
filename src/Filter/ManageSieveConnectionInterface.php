<?php

declare(strict_types=1);

namespace Mailika\Filter;

interface ManageSieveConnectionInterface
{
    public function readLine(): string;

    public function readBytes(int $bytes): string;

    public function write(string $bytes): void;

    public function enableTls(): void;

    public function close(): void;
}

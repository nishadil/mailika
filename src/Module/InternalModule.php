<?php

declare(strict_types=1);

namespace Mailika\Module;

use InvalidArgumentException;

final readonly class InternalModule implements InternalModuleInterface
{
    public function __construct(
        private string $moduleName,
        private bool $active = true,
    ) {
        if (trim($moduleName) === '') {
            throw new InvalidArgumentException('Internal module name cannot be empty.');
        }
    }

    public function name(): string
    {
        return $this->moduleName;
    }

    public function enabled(): bool
    {
        return $this->active;
    }
}

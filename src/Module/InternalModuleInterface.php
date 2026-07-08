<?php

declare(strict_types=1);

namespace Mailika\Module;

interface InternalModuleInterface
{
    public function name(): string;

    public function enabled(): bool;
}

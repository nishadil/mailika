<?php

declare(strict_types=1);

namespace Mailika\Module;

use InvalidArgumentException;

final readonly class InternalModuleRegistry
{
    /** @var array<string, InternalModuleInterface> */
    private array $modules;

    /**
     * @param iterable<InternalModuleInterface> $modules
     */
    public function __construct(iterable $modules)
    {
        $indexed = [];

        foreach ($modules as $module) {
            $name = trim($module->name());
            if ($name === '') {
                throw new InvalidArgumentException('Internal module name cannot be empty.');
            }

            $key = strtolower($name);
            if (isset($indexed[$key])) {
                throw new InvalidArgumentException('Duplicate internal module name: ' . $name);
            }

            $indexed[$key] = $module;
        }

        $this->modules = $indexed;
    }

    /**
     * @return list<InternalModuleInterface>
     */
    public function all(): array
    {
        return array_values($this->modules);
    }

    /**
     * @return list<InternalModuleInterface>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->modules,
            static fn (InternalModuleInterface $module): bool => $module->enabled(),
        ));
    }

    /**
     * @return list<string>
     */
    public function enabledNames(): array
    {
        return array_map(
            static fn (InternalModuleInterface $module): string => $module->name(),
            $this->enabled(),
        );
    }

    public function has(string $name): bool
    {
        return isset($this->modules[strtolower(trim($name))]);
    }

    public function get(string $name): ?InternalModuleInterface
    {
        return $this->modules[strtolower(trim($name))] ?? null;
    }
}

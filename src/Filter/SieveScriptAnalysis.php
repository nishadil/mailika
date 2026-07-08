<?php

declare(strict_types=1);

namespace Mailika\Filter;

final readonly class SieveScriptAnalysis
{
    /**
     * @param list<string> $requiredExtensions
     * @param list<string> $actions
     * @param list<string> $warnings
     */
    public function __construct(
        public int $bytes,
        public int $lines,
        public bool $mailikaOwned,
        public array $requiredExtensions,
        public array $actions,
        public array $warnings,
    ) {
    }

    public function canImportAsLocalRules(): bool
    {
        return $this->mailikaOwned && $this->warnings === [];
    }
}

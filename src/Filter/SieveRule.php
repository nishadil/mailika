<?php

declare(strict_types=1);

namespace Mailika\Filter;

final readonly class SieveRule
{
    /**
     * @param list<string> $vacationExcludedSenders
     * @param list<string> $vacationAddresses
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        public string $matchField,
        public string $matchOperator,
        public string $matchValue,
        public string $action,
        public ?string $actionTarget = null,
        public bool $stopProcessing = true,
        public ?int $id = null,
        public ?int $vacationDays = null,
        public ?string $vacationSubject = null,
        public array $vacationExcludedSenders = [],
        public array $vacationAddresses = [],
    ) {
    }
}

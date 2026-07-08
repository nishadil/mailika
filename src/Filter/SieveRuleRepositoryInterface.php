<?php

declare(strict_types=1);

namespace Mailika\Filter;

interface SieveRuleRepositoryInterface
{
    /**
     * @return list<SieveRule>
     */
    public function listForMailbox(string $mailboxIdentity): array;

    public function save(string $mailboxIdentity, SieveRule $rule): void;

    public function deleteForMailbox(string $mailboxIdentity, int $id): void;
}

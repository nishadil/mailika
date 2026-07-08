<?php

declare(strict_types=1);

namespace Mailika\Filter;

final class SessionSieveRuleRepository implements SieveRuleRepositoryInterface
{
    /**
     * @return list<SieveRule>
     */
    public function listForMailbox(string $mailboxIdentity): array
    {
        $rules = [];
        foreach ($this->rows($mailboxIdentity) as $index => $row) {
            $rules[] = new SieveRule(
                (string) ($row['name'] ?? ''),
                (bool) ($row['enabled'] ?? true),
                (string) ($row['match_field'] ?? 'subject'),
                (string) ($row['match_operator'] ?? 'contains'),
                (string) ($row['match_value'] ?? ''),
                (string) ($row['action'] ?? 'fileinto'),
                $this->optionalString($row['action_target'] ?? null),
                (bool) ($row['stop_processing'] ?? true),
                $index + 1,
                $this->optionalInt($row['vacation_days'] ?? null),
                $this->optionalString($row['vacation_subject'] ?? null),
                $this->stringList($row['vacation_excluded_senders'] ?? []),
                $this->stringList($row['vacation_addresses'] ?? []),
            );
        }

        usort($rules, static fn (SieveRule $a, SieveRule $b): int => $a->name <=> $b->name);

        return $rules;
    }

    public function save(string $mailboxIdentity, SieveRule $rule): void
    {
        $rows = $this->rows($mailboxIdentity);
        $row = $this->row($rule);

        if ($rule->id !== null && array_key_exists($rule->id - 1, $rows)) {
            $rows[$rule->id - 1] = $row;
            $_SESSION['sieve_rules_by_mailbox'][$mailboxIdentity] = $rows;
            return;
        }

        $normalizedName = mb_strtolower($rule->name);
        foreach ($rows as $index => $existing) {
            if (mb_strtolower((string) ($existing['name'] ?? '')) !== $normalizedName) {
                continue;
            }

            $rows[$index] = $row;
            $_SESSION['sieve_rules_by_mailbox'][$mailboxIdentity] = $rows;
            return;
        }

        $_SESSION['sieve_rules_by_mailbox'][$mailboxIdentity][] = $row;
    }

    public function deleteForMailbox(string $mailboxIdentity, int $id): void
    {
        $allRows = $_SESSION['sieve_rules_by_mailbox'] ?? [];
        if (!is_array($allRows)) {
            return;
        }

        $rows = $allRows[$mailboxIdentity] ?? [];
        if (!is_array($rows)) {
            return;
        }

        $index = $id - 1;
        if (!array_key_exists($index, $rows)) {
            return;
        }

        unset($rows[$index]);
        $_SESSION['sieve_rules_by_mailbox'][$mailboxIdentity] = array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $mailboxIdentity): array
    {
        $allRows = $_SESSION['sieve_rules_by_mailbox'] ?? [];
        if (!is_array($allRows)) {
            return [];
        }

        $rows = $allRows[$mailboxIdentity] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SieveRule $rule): array
    {
        return [
            'name' => $rule->name,
            'enabled' => $rule->enabled,
            'match_field' => $rule->matchField,
            'match_operator' => $rule->matchOperator,
            'match_value' => $rule->matchValue,
            'action' => $rule->action,
            'action_target' => $rule->actionTarget,
            'stop_processing' => $rule->stopProcessing,
            'vacation_days' => $rule->vacationDays,
            'vacation_subject' => $rule->vacationSubject,
            'vacation_excluded_senders' => $rule->vacationExcludedSenders,
            'vacation_addresses' => $rule->vacationAddresses,
        ];
    }

    private function optionalInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}

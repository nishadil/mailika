<?php

declare(strict_types=1);

namespace Mailika\Filter;

use PDO;

final readonly class SieveRuleRepository implements SieveRuleRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<SieveRule>
     */
    public function listForMailbox(string $mailboxIdentity): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, enabled, match_field, match_operator, match_value,
                action, action_target, stop_processing, vacation_days, vacation_subject,
                vacation_excluded_senders, vacation_addresses
             FROM sieve_rules
             WHERE mailbox_identity = :mailbox
             ORDER BY name',
        );
        $statement->execute(['mailbox' => $mailboxIdentity]);

        $rules = [];
        foreach ($statement->fetchAll() as $row) {
            $rules[] = new SieveRule(
                (string) $row['name'],
                $this->boolValue($row['enabled'] ?? true),
                (string) $row['match_field'],
                (string) $row['match_operator'],
                (string) $row['match_value'],
                (string) $row['action'],
                $row['action_target'] === null ? null : (string) $row['action_target'],
                $this->boolValue($row['stop_processing'] ?? true),
                (int) $row['id'],
                $row['vacation_days'] === null ? null : (int) $row['vacation_days'],
                $row['vacation_subject'] === null ? null : (string) $row['vacation_subject'],
                $this->stringList($row['vacation_excluded_senders'] ?? null),
                $this->stringList($row['vacation_addresses'] ?? null),
            );
        }

        return $rules;
    }

    public function save(string $mailboxIdentity, SieveRule $rule): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sieve_rules (
                mailbox_identity, name, enabled, match_field, match_operator, match_value,
                action, action_target, stop_processing, vacation_days, vacation_subject,
                vacation_excluded_senders, vacation_addresses
             )
             VALUES (
                :mailbox, :name, :enabled, :match_field, :match_operator, :match_value,
                :action, :action_target, :stop_processing, :vacation_days, :vacation_subject,
                :vacation_excluded_senders, :vacation_addresses
             )
             ON CONFLICT (mailbox_identity, (lower(name))) DO UPDATE SET
                name = EXCLUDED.name,
                enabled = EXCLUDED.enabled,
                match_field = EXCLUDED.match_field,
                match_operator = EXCLUDED.match_operator,
                match_value = EXCLUDED.match_value,
                action = EXCLUDED.action,
                action_target = EXCLUDED.action_target,
                stop_processing = EXCLUDED.stop_processing,
                vacation_days = EXCLUDED.vacation_days,
                vacation_subject = EXCLUDED.vacation_subject,
                vacation_excluded_senders = EXCLUDED.vacation_excluded_senders,
                vacation_addresses = EXCLUDED.vacation_addresses,
                updated_at = CURRENT_TIMESTAMP',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'name' => $rule->name,
            'enabled' => $rule->enabled ? 1 : 0,
            'match_field' => $rule->matchField,
            'match_operator' => $rule->matchOperator,
            'match_value' => $rule->matchValue,
            'action' => $rule->action,
            'action_target' => $rule->actionTarget,
            'stop_processing' => $rule->stopProcessing ? 1 : 0,
            'vacation_days' => $rule->vacationDays,
            'vacation_subject' => $rule->vacationSubject,
            'vacation_excluded_senders' => $this->encodedStringList($rule->vacationExcludedSenders),
            'vacation_addresses' => $this->encodedStringList($rule->vacationAddresses),
        ]);
    }

    public function deleteForMailbox(string $mailboxIdentity, int $id): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM sieve_rules
             WHERE mailbox_identity = :mailbox AND id = :id',
        );
        $statement->execute([
            'mailbox' => $mailboxIdentity,
            'id' => $id,
        ]);
    }

    private function boolValue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    /**
     * @param list<string> $values
     */
    private function encodedStringList(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $encoded = json_encode($values);
        return is_string($encoded) ? $encoded : null;
    }
}

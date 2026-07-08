<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class MailboxRights
{
    public string $raw;

    public function __construct(string $raw = '')
    {
        $this->raw = preg_replace('/[^A-Za-z]/', '', $raw) ?? '';
    }

    public function available(): bool
    {
        return $this->raw !== '';
    }

    public function canLookup(): bool
    {
        return $this->has('l');
    }

    public function canRead(): bool
    {
        return $this->has('r');
    }

    public function canKeepSeen(): bool
    {
        return $this->has('s');
    }

    public function canWriteFlags(): bool
    {
        return $this->has('w');
    }

    public function canInsert(): bool
    {
        return $this->has('i');
    }

    public function canPost(): bool
    {
        return $this->has('p');
    }

    public function canCreateMailbox(): bool
    {
        return $this->has('k') || $this->has('c');
    }

    public function canDeleteMailbox(): bool
    {
        return $this->has('x');
    }

    public function canDeleteMessages(): bool
    {
        return $this->has('t') || $this->has('d');
    }

    public function canExpunge(): bool
    {
        return $this->has('e') || $this->has('d');
    }

    public function canAdminister(): bool
    {
        return $this->has('a');
    }

    private function has(string $right): bool
    {
        return str_contains(strtolower($this->raw), $right);
    }
}

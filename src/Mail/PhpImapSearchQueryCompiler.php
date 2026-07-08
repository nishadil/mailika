<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class PhpImapSearchQueryCompiler
{
    public function compile(MessageSearchCriteria $criteria): string
    {
        $parts = [];

        if ($criteria->query !== '') {
            $parts[] = 'TEXT ' . $this->quoted($criteria->query);
        }

        if ($criteria->unseenOnly) {
            $parts[] = 'UNSEEN';
        }

        if ($criteria->flaggedOnly) {
            $parts[] = 'FLAGGED';
        }

        if ($criteria->from !== null && $criteria->from !== '') {
            $parts[] = 'FROM ' . $this->quoted($criteria->from);
        }

        if ($criteria->to !== null && $criteria->to !== '') {
            $parts[] = 'TO ' . $this->quoted($criteria->to);
        }

        if ($criteria->subject !== null && $criteria->subject !== '') {
            $parts[] = 'SUBJECT ' . $this->quoted($criteria->subject);
        }

        return $parts === [] ? 'ALL' : implode(' ', $parts);
    }

    private function quoted(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $clean = preg_replace('/\s+/u', ' ', trim(is_string($clean) ? $clean : $value));
        $clean = is_string($clean) ? $clean : trim($value);

        return '"' . addcslashes($clean, "\\\"") . '"';
    }
}

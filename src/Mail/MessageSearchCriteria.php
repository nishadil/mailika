<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class MessageSearchCriteria
{
    public function __construct(
        public string $folder = 'INBOX',
        public string $query = '',
        public int $page = 1,
        public int $limit = 50,
        public bool $unseenOnly = false,
        public bool $flaggedOnly = false,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $subject = null,
    ) {
    }

    public static function fromRawInput(
        string $folder,
        string $query,
        int $page,
        int $limit,
        string $from = '',
        string $to = '',
        string $subject = '',
        bool $unseenOnly = false,
        bool $flaggedOnly = false,
    ): self {
        $parsed = self::parseQuery($query);

        return new self(
            self::cleanText($folder, 512) ?: 'INBOX',
            $parsed['query'],
            max(1, $page),
            max(1, min(100, $limit)),
            $unseenOnly || $parsed['unseen'],
            $flaggedOnly || $parsed['flagged'],
            self::firstNonEmpty($from, $parsed['from']),
            self::firstNonEmpty($to, $parsed['to']),
            self::firstNonEmpty($subject, $parsed['subject']),
        );
    }

    public function canonicalQuery(): string
    {
        $tokens = [];

        if ($this->from !== null && $this->from !== '') {
            $tokens[] = 'from:' . self::quoteValue($this->from);
        }

        if ($this->to !== null && $this->to !== '') {
            $tokens[] = 'to:' . self::quoteValue($this->to);
        }

        if ($this->subject !== null && $this->subject !== '') {
            $tokens[] = 'subject:' . self::quoteValue($this->subject);
        }

        if ($this->unseenOnly) {
            $tokens[] = 'is:unread';
        }

        if ($this->flaggedOnly) {
            $tokens[] = 'is:flagged';
        }

        if ($this->query !== '') {
            $tokens[] = $this->query;
        }

        return implode(' ', $tokens);
    }

    /**
     * @return array{
     *     query:string,
     *     from:string,
     *     to:string,
     *     subject:string,
     *     unseen:bool,
     *     flagged:bool
     * }
     */
    private static function parseQuery(string $query): array
    {
        $parsed = self::emptyParsedQuery();

        $query = self::cleanText($query, 1024);
        $withoutFieldOperators = preg_replace_callback(
            '/(?:^|\s)(from|to|subject):(?:"([^"]*)"|([^\s]+))/iu',
            static function (array $matches) use (&$parsed): string {
                $field = strtolower((string) $matches[1]);
                $value = self::cleanText(self::fieldOperatorValue($matches), 255);

                if ($value === '') {
                    return ' ';
                }

                if ($field === 'from' && $parsed['from'] === '') {
                    $parsed['from'] = $value;
                } elseif ($field === 'to' && $parsed['to'] === '') {
                    $parsed['to'] = $value;
                } elseif ($field === 'subject' && $parsed['subject'] === '') {
                    $parsed['subject'] = $value;
                }

                return ' ';
            },
            $query,
        );

        $withoutFieldOperators = is_string($withoutFieldOperators) ? $withoutFieldOperators : $query;
        $withoutFlags = preg_replace_callback(
            '/(?:^|\s)is:(unread|unseen|flagged|starred)\b/iu',
            static function (array $matches) use (&$parsed): string {
                $flag = strtolower((string) $matches[1]);
                if ($flag === 'unread' || $flag === 'unseen') {
                    $parsed['unseen'] = true;
                } else {
                    $parsed['flagged'] = true;
                }

                return ' ';
            },
            $withoutFieldOperators,
        );

        $withoutFlags = is_string($withoutFlags) ? $withoutFlags : $withoutFieldOperators;
        $parsed['query'] = self::normalizeWhitespace($withoutFlags);

        return $parsed;
    }

    /**
     * @return array{
     *     query:string,
     *     from:string,
     *     to:string,
     *     subject:string,
     *     unseen:bool,
     *     flagged:bool
     * }
     */
    private static function emptyParsedQuery(): array
    {
        return [
            'query' => '',
            'from' => '',
            'to' => '',
            'subject' => '',
            'unseen' => false,
            'flagged' => false,
        ];
    }

    /**
     * @param array<int, string> $matches
     */
    private static function fieldOperatorValue(array $matches): string
    {
        if (isset($matches[2]) && $matches[2] !== '') {
            return $matches[2];
        }

        return $matches[3] ?? '';
    }

    private static function firstNonEmpty(string $primary, string $fallback): ?string
    {
        $primary = self::cleanText($primary, 255);
        if ($primary !== '') {
            return $primary;
        }

        $fallback = self::cleanText($fallback, 255);
        return $fallback === '' ? null : $fallback;
    }

    private static function cleanText(string $value, int $limit): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        return mb_substr(self::normalizeWhitespace(is_string($clean) ? $clean : $value), 0, $limit);
    }

    private static function normalizeWhitespace(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));
        return is_string($normalized) ? $normalized : trim($value);
    }

    private static function quoteValue(string $value): string
    {
        $value = str_replace('"', '', self::cleanText($value, 255));
        return preg_match('/\s/u', $value) === 1 ? '"' . $value . '"' : $value;
    }
}

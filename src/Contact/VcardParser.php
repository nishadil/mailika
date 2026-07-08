<?php

declare(strict_types=1);

namespace Mailika\Contact;

use Mailika\Validation\Validator;

final readonly class VcardParser
{
    /**
     * @return list<Contact>
     */
    public function parse(string $content): array
    {
        $cards = [];
        $current = [];

        foreach ($this->unfold($content) as $line) {
            $upper = strtoupper($line);
            if ($upper === 'BEGIN:VCARD') {
                $current = [];
                continue;
            }

            if ($upper === 'END:VCARD') {
                $contact = $this->contactFromLines($current);
                if ($contact !== null) {
                    $cards[] = $contact;
                }
                $current = [];
                continue;
            }

            if ($current !== [] || str_contains($line, ':')) {
                $current[] = $line;
            }
        }

        return $cards;
    }

    /**
     * @return list<string>
     */
    private function unfold(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $unfolded = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^[ \t]/', $line) === 1 && $unfolded !== []) {
                $unfolded[array_key_last($unfolded)] .= ltrim($line);
                continue;
            }

            $unfolded[] = trim($line);
        }

        return $unfolded;
    }

    /**
     * @param list<string> $lines
     */
    private function contactFromLines(array $lines): ?Contact
    {
        $name = '';
        $email = '';
        $notes = '';
        $groups = [];

        foreach ($lines as $line) {
            [$property, $value] = $this->splitProperty($line);
            $namePart = strtoupper(strtok($property, ';') ?: $property);

            if ($namePart === 'FN' && $name === '') {
                $name = $this->cleanText($value, 255);
            }

            if ($namePart === 'EMAIL' && $email === '') {
                $candidate = Validator::normalizeEmail($this->cleanText($value, 320));
                if (Validator::email($candidate)) {
                    $email = $candidate;
                }
            }

            if ($namePart === 'NOTE' && $notes === '') {
                $notes = $this->cleanText($value, 2000);
            }

            if ($namePart === 'CATEGORIES') {
                $groups = array_values(array_unique(array_merge($groups, $this->cleanTextList($value, 255))));
            }
        }

        if ($email === '') {
            return null;
        }

        return new Contact($name === '' ? $email : $name, $email, $notes, null, $groups);
    }

    /**
     * @return array{string, string}
     */
    private function splitProperty(string $line): array
    {
        $position = strpos($line, ':');
        if ($position === false) {
            return [$line, ''];
        }

        return [substr($line, 0, $position), substr($line, $position + 1)];
    }

    private function cleanText(string $value, int $limit): string
    {
        $value = str_replace(['\n', '\N'], "\n", $value);
        $value = str_replace(['\\,', '\\;', '\\\\'], [',', ';', '\\'], $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';

        return mb_substr(trim($value), 0, $limit);
    }

    /**
     * @return list<string>
     */
    private function cleanTextList(string $value, int $itemLimit): array
    {
        $placeholder = '__MAILIKA_ESCAPED_COMMA__';
        $items = explode(',', str_replace('\\,', $placeholder, $value));
        $clean = [];

        foreach ($items as $item) {
            $item = $this->cleanText(str_replace($placeholder, '\\,', $item), $itemLimit);
            if ($item !== '') {
                $clean[] = $item;
            }
        }

        return array_values(array_unique($clean));
    }
}

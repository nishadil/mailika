<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class PhpImapCapabilityParser
{
    /**
     * @param list<string> $raw
     */
    public function parse(array $raw): MailboxCapabilities
    {
        $capabilities = $this->normalize($raw);

        return new MailboxCapabilities(
            in_array('MOVE', $capabilities, true),
            in_array('QUOTA', $capabilities, true),
            in_array('ACL', $capabilities, true),
            in_array('IDLE', $capabilities, true),
            in_array('SORT', $capabilities, true),
            in_array('THREAD=REFERENCES', $capabilities, true)
                || in_array('THREAD=ORDEREDSUBJECT', $capabilities, true),
            ['LEGACY-EXT-IMAP', ...$capabilities],
        );
    }

    /**
     * @param list<string> $raw
     * @return list<string>
     */
    private function normalize(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $capability) {
            $capability = strtoupper(trim($capability));
            if ($capability !== '') {
                $normalized[] = $capability;
            }
        }

        return array_values(array_unique($normalized));
    }
}

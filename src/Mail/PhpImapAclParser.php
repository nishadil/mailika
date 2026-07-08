<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class PhpImapAclParser
{
    /**
     * @return list<MailboxAclEntry>
     */
    public function parse(mixed $acl): array
    {
        if (!is_array($acl)) {
            return [];
        }

        $entries = [];
        foreach ($acl as $identifier => $rights) {
            if (!is_string($identifier) || !is_scalar($rights) || trim($identifier) === '') {
                continue;
            }

            $entry = new MailboxAclEntry($identifier, new MailboxRights((string) $rights));
            if ($entry->identifier !== '' && $entry->rights->available()) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param list<MailboxAclEntry> $entries
     */
    public function rightsFor(array $entries, string $identifier): MailboxRights
    {
        foreach ($entries as $entry) {
            if (strcasecmp($entry->identifier, $identifier) === 0) {
                return $entry->rights;
            }
        }

        return new MailboxRights();
    }
}

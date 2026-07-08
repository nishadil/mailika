<?php

declare(strict_types=1);

namespace Mailika\Mail;

final readonly class MailboxAclEntry
{
    public string $identifier;

    public function __construct(string $identifier, public MailboxRights $rights)
    {
        $this->identifier = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', '', $identifier) ?? ''), 0, 255);
    }
}

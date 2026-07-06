<?php

declare(strict_types=1);

namespace Mailika\Mail;

use Mailika\Auth\MailboxCredentials;

interface MailboxClientInterface
{
    public function authenticate(MailboxCredentials $credentials): void;

    /**
     * @return list<Folder>
     */
    public function folders(MailboxCredentials $credentials): array;

    /**
     * @return list<MessageSummary>
     */
    public function messages(MailboxCredentials $credentials, string $folder, int $limit = 50): array;

    public function message(MailboxCredentials $credentials, string $folder, string $id): Message;
}

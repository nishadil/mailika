<?php

declare(strict_types=1);

namespace Mailika\Mail;

use Mailika\Auth\MailboxCredentials;

interface MailboxClientInterface
{
    public function authenticate(MailboxCredentials $credentials): void;

    /**
     * @return list<MailboxFolder>
     */
    public function folders(MailboxCredentials $credentials): array;

    /**
     * @return list<MessageEnvelope>
     */
    public function messages(MailboxCredentials $credentials, string $folder, int $limit = 50): array;

    /**
     * @return list<MessageEnvelope>
     */
    public function search(MailboxCredentials $credentials, MessageSearchCriteria $criteria): array;

    public function message(MailboxCredentials $credentials, string $folder, string $id): Message;

    public function attachment(
        MailboxCredentials $credentials,
        string $folder,
        string $messageId,
        string $attachmentId,
    ): MessageAttachment;

    public function markSeen(MailboxCredentials $credentials, string $folder, string $id, bool $seen): void;

    public function flag(MailboxCredentials $credentials, string $folder, string $id, bool $flagged): void;

    public function move(MailboxCredentials $credentials, string $folder, string $id, string $targetFolder): void;

    public function copy(MailboxCredentials $credentials, string $folder, string $id, string $targetFolder): void;

    public function delete(MailboxCredentials $credentials, string $folder, string $id): void;

    public function createFolder(MailboxCredentials $credentials, string $folder): void;

    public function renameFolder(MailboxCredentials $credentials, string $folder, string $newName): void;

    public function deleteFolder(MailboxCredentials $credentials, string $folder): void;

    public function capabilities(MailboxCredentials $credentials): MailboxCapabilities;

    public function mailboxRights(MailboxCredentials $credentials, string $folder): MailboxRights;

    /**
     * @return list<MailboxAclEntry>
     */
    public function mailboxAcl(MailboxCredentials $credentials, string $folder): array;

    public function setMailboxAcl(
        MailboxCredentials $credentials,
        string $folder,
        string $identifier,
        MailboxRights $rights,
    ): void;

    public function deleteMailboxAcl(MailboxCredentials $credentials, string $folder, string $identifier): void;

    public function quota(MailboxCredentials $credentials, string $folder = 'INBOX'): MailboxQuota;
}

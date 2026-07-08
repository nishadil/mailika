<?php

declare(strict_types=1);

namespace Mailika\Tests\Integration;

use Mailika\Auth\MailboxCredentials;
use Mailika\Mail\MailboxCapabilities;
use Mailika\Mail\MailboxAclEntry;
use Mailika\Mail\MailboxClientInterface;
use Mailika\Mail\MailboxFolder;
use Mailika\Mail\MailboxQuota;
use Mailika\Mail\MailboxRights;
use Mailika\Mail\Message;
use Mailika\Mail\MessageAttachment;
use Mailika\Mail\MessageEnvelope;
use Mailika\Mail\MessageSearchCriteria;
use RuntimeException;

final class FakeMailboxClient implements MailboxClientInterface
{
    /** @var list<string> */
    public array $actions = [];

    /** @var list<string> */
    public array $throwOnActions = [];

    /** @var list<MailboxFolder>|null */
    public ?array $folderFixtures = null;

    public ?Message $message = null;

    public ?MessageAttachment $attachment = null;

    public ?MailboxCapabilities $capabilitiesFixture = null;

    public ?MailboxRights $rightsFixture = null;

    /** @var list<MailboxAclEntry> */
    public array $aclFixtures = [];

    public ?MessageSearchCriteria $lastSearchCriteria = null;

    public function authenticate(MailboxCredentials $credentials): void
    {
    }

    public function folders(MailboxCredentials $credentials): array
    {
        if ($this->folderFixtures !== null) {
            return $this->folderFixtures;
        }

        return [new MailboxFolder('INBOX', 'Inbox')];
    }

    public function messages(MailboxCredentials $credentials, string $folder, int $limit = 50): array
    {
        return [new MessageEnvelope('1', 'sender@example.com', 'Hello', 'today', false, false)];
    }

    public function search(MailboxCredentials $credentials, MessageSearchCriteria $criteria): array
    {
        $this->lastSearchCriteria = $criteria;

        return $this->messages($credentials, $criteria->folder, $criteria->limit);
    }

    public function message(MailboxCredentials $credentials, string $folder, string $id): Message
    {
        if ($this->message !== null) {
            return $this->message;
        }

        return new Message($id, 'sender@example.com', $credentials->email, 'Hello', 'today', '<p>Hello</p>', 'Hello');
    }

    public function attachment(
        MailboxCredentials $credentials,
        string $folder,
        string $messageId,
        string $attachmentId,
    ): MessageAttachment {
        if ($this->attachment !== null) {
            return $this->attachment;
        }

        return new MessageAttachment($attachmentId, 'a.txt', 'text/plain', 4, 'test');
    }

    public function markSeen(MailboxCredentials $credentials, string $folder, string $id, bool $seen): void
    {
        $this->actions[] = $seen ? 'seen' : 'unseen';
    }

    public function flag(MailboxCredentials $credentials, string $folder, string $id, bool $flagged): void
    {
        $this->actions[] = $flagged ? 'flagged' : 'unflagged';
    }

    public function move(MailboxCredentials $credentials, string $folder, string $id, string $targetFolder): void
    {
        $this->actions[] = 'move';
    }

    public function copy(MailboxCredentials $credentials, string $folder, string $id, string $targetFolder): void
    {
        $this->actions[] = 'copy';
    }

    public function delete(MailboxCredentials $credentials, string $folder, string $id): void
    {
        $this->actions[] = 'delete';
    }

    public function createFolder(MailboxCredentials $credentials, string $folder): void
    {
        $this->throwIfConfigured('create');
        $this->actions[] = 'create:' . $folder;
    }

    public function renameFolder(MailboxCredentials $credentials, string $folder, string $newName): void
    {
        $this->throwIfConfigured('rename');
        $this->actions[] = 'rename:' . $folder . ':' . $newName;
    }

    public function deleteFolder(MailboxCredentials $credentials, string $folder): void
    {
        $this->throwIfConfigured('delete');
        $this->actions[] = 'delete:' . $folder;
    }

    public function capabilities(MailboxCredentials $credentials): MailboxCapabilities
    {
        return $this->capabilitiesFixture ?? new MailboxCapabilities(move: true);
    }

    public function mailboxRights(MailboxCredentials $credentials, string $folder): MailboxRights
    {
        return $this->rightsFixture ?? new MailboxRights();
    }

    public function mailboxAcl(MailboxCredentials $credentials, string $folder): array
    {
        return $this->aclFixtures;
    }

    public function setMailboxAcl(
        MailboxCredentials $credentials,
        string $folder,
        string $identifier,
        MailboxRights $rights,
    ): void {
        $this->actions[] = 'set-acl:' . $folder . ':' . $identifier . ':' . $rights->raw;
    }

    public function deleteMailboxAcl(MailboxCredentials $credentials, string $folder, string $identifier): void
    {
        $this->actions[] = 'delete-acl:' . $folder . ':' . $identifier;
    }

    public function quota(MailboxCredentials $credentials, string $folder = 'INBOX'): MailboxQuota
    {
        return new MailboxQuota();
    }

    private function throwIfConfigured(string $action): void
    {
        if (in_array($action, $this->throwOnActions, true)) {
            throw new RuntimeException('Configured fake mailbox failure.');
        }
    }
}

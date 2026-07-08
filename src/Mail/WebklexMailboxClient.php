<?php

declare(strict_types=1);

namespace Mailika\Mail;

use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Throwable;
use Webklex\PHPIMAP\Attachment as WebklexAttachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Folder as WebklexFolder;
use Webklex\PHPIMAP\Message as WebklexMessage;
use Webklex\PHPIMAP\Query\WhereQuery;

final readonly class WebklexMailboxClient implements MailboxClientInterface
{
    public function __construct(private Config $config)
    {
    }

    public function authenticate(MailboxCredentials $credentials): void
    {
        $this->withClient($credentials, static fn (): null => null);
    }

    public function folders(MailboxCredentials $credentials): array
    {
        return $this->withClient($credentials, function (Client $client): array {
            $folders = [];

            foreach ($client->getFoldersWithStatus() as $folder) {
                if ($folder instanceof WebklexFolder) {
                    $this->appendFolder($folders, $folder);
                }
            }

            return $folders === [] ? [new MailboxFolder('INBOX', 'Inbox')] : $folders;
        });
    }

    public function messages(MailboxCredentials $credentials, string $folder, int $limit = 50): array
    {
        return $this->search($credentials, new MessageSearchCriteria($folder, '', 1, $limit));
    }

    public function search(MailboxCredentials $credentials, MessageSearchCriteria $criteria): array
    {
        return $this->withClient($credentials, function (Client $client) use ($criteria): array {
            $folder = $this->folder($client, $criteria->folder);
            $query = $this->applySearchCriteria($folder->messages(), $criteria);
            $messages = [];

            $results = $query->leaveUnread()->fetchOrderDesc()->limit($criteria->limit, $criteria->page)->get();
            foreach ($results as $message) {
                if ($message instanceof WebklexMessage) {
                    $messages[] = $this->envelopeFromMessage($message);
                }
            }

            return $messages;
        });
    }

    public function message(MailboxCredentials $credentials, string $folder, string $id): Message
    {
        return $this->withClient($credentials, function (Client $client) use ($folder, $id): Message {
            $message = $this->webklexMessage($client, $folder, $id);
            $html = $message->getHTMLBody();
            $text = $message->getTextBody();
            $attachments = $this->attachmentsFromMessage($message, false);

            return new Message(
                (string) $message->get('uid'),
                $this->attribute($message, 'from'),
                $this->attribute($message, 'to'),
                $this->attribute($message, 'subject', '(no subject)'),
                $this->attribute($message, 'date'),
                $html !== '' ? $html : nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                $text,
                array_map(
                    static fn (MessageAttachment $attachment): Attachment => new Attachment(
                        $attachment->filename,
                        $attachment->contentType,
                        $attachment->bytes,
                        $attachment->id,
                        $attachment->inline,
                        $attachment->contentId,
                    ),
                    $attachments,
                ),
                $this->attribute($message, 'cc'),
                $this->attribute($message, 'reply_to'),
                $this->attribute($message, 'message_id') ?: null,
            );
        });
    }

    public function attachment(
        MailboxCredentials $credentials,
        string $folder,
        string $messageId,
        string $attachmentId,
    ): MessageAttachment {
        return $this->withClient(
            $credentials,
            function (Client $client) use ($folder, $messageId, $attachmentId): MessageAttachment {
                $message = $this->webklexMessage($client, $folder, $messageId);

                return $this->attachmentFromMessage($message, $attachmentId);
            },
        );
    }

    public function markSeen(MailboxCredentials $credentials, string $folder, string $id, bool $seen): void
    {
        $this->withClient($credentials, function (Client $client) use ($folder, $id, $seen): null {
            $message = $this->webklexMessage($client, $folder, $id);
            $seen ? $message->setFlag('Seen') : $message->unsetFlag('Seen');

            return null;
        });
    }

    public function flag(MailboxCredentials $credentials, string $folder, string $id, bool $flagged): void
    {
        $this->withClient($credentials, function (Client $client) use ($folder, $id, $flagged): null {
            $message = $this->webklexMessage($client, $folder, $id);
            $flagged ? $message->setFlag('Flagged') : $message->unsetFlag('Flagged');

            return null;
        });
    }

    public function move(MailboxCredentials $credentials, string $folder, string $id, string $targetFolder): void
    {
        $this->withClient($credentials, function (Client $client) use ($folder, $id, $targetFolder): null {
            $this->webklexMessage($client, $folder, $id)->move($targetFolder, true);

            return null;
        });
    }

    public function copy(MailboxCredentials $credentials, string $folder, string $id, string $targetFolder): void
    {
        $this->withClient($credentials, function (Client $client) use ($folder, $id, $targetFolder): null {
            $this->webklexMessage($client, $folder, $id)->copy($targetFolder, true);

            return null;
        });
    }

    public function delete(MailboxCredentials $credentials, string $folder, string $id): void
    {
        $this->withClient($credentials, function (Client $client) use ($folder, $id): null {
            $this->deleteMessage($this->webklexMessage($client, $folder, $id));

            return null;
        });
    }

    public function createFolder(MailboxCredentials $credentials, string $folder): void
    {
        $this->withClient($credentials, static function (Client $client) use ($folder): null {
            $client->createFolder($folder, false);

            return null;
        });
    }

    public function renameFolder(MailboxCredentials $credentials, string $folder, string $newName): void
    {
        $this->withClient($credentials, function (Client $client) use ($folder, $newName): null {
            $this->folder($client, $folder)->move($newName, false);

            return null;
        });
    }

    public function deleteFolder(MailboxCredentials $credentials, string $folder): void
    {
        $this->withClient($credentials, static function (Client $client) use ($folder): null {
            $client->deleteFolder($folder, false);

            return null;
        });
    }

    public function capabilities(MailboxCredentials $credentials): MailboxCapabilities
    {
        return $this->withClient($credentials, function (Client $client): MailboxCapabilities {
            $raw = $this->rawCapabilities($client);
            $upper = array_map('strtoupper', $raw);

            return new MailboxCapabilities(
                in_array('MOVE', $upper, true),
                in_array('QUOTA', $upper, true),
                in_array('ACL', $upper, true),
                in_array('IDLE', $upper, true),
                in_array('SORT', $upper, true),
                in_array('THREAD=REFERENCES', $upper, true) || in_array('THREAD=ORDEREDSUBJECT', $upper, true),
                $raw,
            );
        });
    }

    public function mailboxRights(MailboxCredentials $credentials, string $folder): MailboxRights
    {
        return $this->withClient($credentials, function (Client $client) use ($folder): MailboxRights {
            if (!$this->supportsCapability($client, 'ACL')) {
                return new MailboxRights();
            }

            $connection = $client->getConnection();
            if (!$connection instanceof ImapProtocol) {
                return new MailboxRights();
            }

            try {
                $escapedFolder = $connection->escapeString($folder);
                if (!is_string($escapedFolder)) {
                    return new MailboxRights();
                }

                $response = $connection->requestAndResponse('MYRIGHTS', [$escapedFolder]);
                return $this->rightsFromMyRightsResponse($response->getResponse());
            } catch (Throwable) {
                return new MailboxRights();
            }
        });
    }

    public function mailboxAcl(MailboxCredentials $credentials, string $folder): array
    {
        return $this->withClient($credentials, function (Client $client) use ($folder): array {
            if (!$this->supportsCapability($client, 'ACL')) {
                return [];
            }

            $connection = $client->getConnection();
            if (!$connection instanceof ImapProtocol) {
                return [];
            }

            try {
                $escapedFolder = $connection->escapeString($folder);
                if (!is_string($escapedFolder)) {
                    return [];
                }

                $response = $connection->requestAndResponse('GETACL', [$escapedFolder]);
                return $this->aclFromGetAclResponse($response->getResponse());
            } catch (Throwable) {
                return [];
            }
        });
    }

    public function setMailboxAcl(
        MailboxCredentials $credentials,
        string $folder,
        string $identifier,
        MailboxRights $rights,
    ): void {
        if (!$rights->available()) {
            throw new MailboxUnavailableException('ACL rights cannot be empty.');
        }

        $this->withClient(
            $credentials,
            function (Client $client) use ($folder, $identifier, $rights): null {
                $connection = $this->aclConnection($client);
                $folderToken = $connection->escapeString($folder);
                $identifierToken = $connection->escapeString($identifier);
                $rightsToken = $connection->escapeString($rights->raw);

                if (!is_string($folderToken) || !is_string($identifierToken) || !is_string($rightsToken)) {
                    throw new MailboxUnavailableException('ACL tokens cannot contain line breaks.');
                }

                $connection->requestAndResponse('SETACL', [$folderToken, $identifierToken, $rightsToken]);
                return null;
            },
        );
    }

    public function deleteMailboxAcl(MailboxCredentials $credentials, string $folder, string $identifier): void
    {
        $this->withClient(
            $credentials,
            function (Client $client) use ($folder, $identifier): null {
                $connection = $this->aclConnection($client);
                $folderToken = $connection->escapeString($folder);
                $identifierToken = $connection->escapeString($identifier);

                if (!is_string($folderToken) || !is_string($identifierToken)) {
                    throw new MailboxUnavailableException('ACL tokens cannot contain line breaks.');
                }

                $connection->requestAndResponse('DELETEACL', [$folderToken, $identifierToken]);
                return null;
            },
        );
    }

    public function quota(MailboxCredentials $credentials, string $folder = 'INBOX'): MailboxQuota
    {
        return $this->withClient($credentials, static function (Client $client) use ($folder): MailboxQuota {
            try {
                $quota = $client->getQuotaRoot($folder);
            } catch (Throwable) {
                return new MailboxQuota();
            }

            $used = $quota['STORAGE']['usage'] ?? $quota['storage']['usage'] ?? null;
            $limit = $quota['STORAGE']['limit'] ?? $quota['storage']['limit'] ?? null;

            return new MailboxQuota(
                is_numeric($used) ? (int) $used * 1024 : null,
                is_numeric($limit) ? (int) $limit * 1024 : null,
            );
        });
    }

    private function client(MailboxCredentials $credentials): Client
    {
        $manager = new ClientManager();
        $client = $manager->make([
            'host' => $credentials->imapHost,
            'port' => $credentials->imapPort,
            'protocol' => 'imap',
            'encryption' => $credentials->imapTls ? 'ssl' : 'notls',
            'validate_cert' => $this->config->bool('mail.require_tls', true),
            'username' => $credentials->email,
            'password' => $credentials->password,
            'authentication' => null,
            'timeout' => 30,
        ]);

        return $this->connect($client);
    }

    private function connect(Client $client): Client
    {
        set_error_handler(static fn (): bool => true);

        try {
            $connected = $client->connect();
        } catch (Throwable $exception) {
            throw new MailboxUnavailableException('Unable to connect to the IMAP mailbox.', 0, $exception);
        } finally {
            restore_error_handler();
        }

        return $connected;
    }

    /**
     * @template T
     * @param callable(Client): T $callback
     * @return T
     */
    private function withClient(MailboxCredentials $credentials, callable $callback): mixed
    {
        $client = $this->client($credentials);

        try {
            return $callback($client);
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
            }
        }
    }

    private function folder(Client $client, string $folder): WebklexFolder
    {
        $mailbox = $client->getFolderByPath($folder, false, true);
        if (!$mailbox instanceof WebklexFolder) {
            throw new MailboxUnavailableException('Mailbox folder not found.');
        }

        return $mailbox;
    }

    private function webklexMessage(Client $client, string $folder, string $id): WebklexMessage
    {
        $uid = filter_var($id, FILTER_VALIDATE_INT);
        if ($uid === false) {
            throw new MailboxUnavailableException('Invalid message identifier.');
        }

        return $this->folder($client, $folder)->messages()->leaveUnread()->getMessageByUid((int) $uid);
    }

    private function deleteMessage(WebklexMessage $message): void
    {
        try {
            $message->delete(true);
            return;
        } catch (Throwable $exception) {
            if (!str_contains(strtoupper($exception->getMessage()), 'EXPUNGE')) {
                throw $exception;
            }
        }

        $message->delete(false);
    }

    private function applySearchCriteria(WhereQuery $query, MessageSearchCriteria $criteria): WhereQuery
    {
        $query = $query->whereAll();

        if ($criteria->query !== '') {
            $query = $query->whereText($criteria->query);
        }

        if ($criteria->unseenOnly) {
            $query = $query->whereUnseen();
        }

        if ($criteria->flaggedOnly) {
            $query = $query->whereFlagged('\\Flagged');
        }

        if ($criteria->from !== null && $criteria->from !== '') {
            $query = $query->whereFrom($criteria->from);
        }

        if ($criteria->to !== null && $criteria->to !== '') {
            $query = $query->whereTo($criteria->to);
        }

        if ($criteria->subject !== null && $criteria->subject !== '') {
            $query = $query->whereSubject($criteria->subject);
        }

        return $query;
    }

    /**
     * @param list<MailboxFolder> $folders
     */
    private function appendFolder(array &$folders, WebklexFolder $folder): void
    {
        $folders[] = new MailboxFolder(
            $folder->path,
            $folder->name,
            $folder->delimiter,
            $this->unreadCount($folder),
            !$folder->no_select,
            $folder->has_children,
            $this->specialUse($folder),
        );

        foreach ($folder->getChildren() as $child) {
            if ($child instanceof WebklexFolder) {
                $this->appendFolder($folders, $child);
            }
        }
    }

    private function envelopeFromMessage(WebklexMessage $message): MessageEnvelope
    {
        return new MessageEnvelope(
            (string) $message->get('uid'),
            $this->attribute($message, 'from'),
            $this->attribute($message, 'subject', '(no subject)'),
            $this->attribute($message, 'date'),
            $message->hasFlag('Seen'),
            $message->hasAttachments(),
            $message->hasFlag('Flagged'),
            $message->hasFlag('Answered'),
            $message->hasFlag('Deleted'),
            $message->hasFlag('Draft'),
            $this->attribute($message, 'message_id') ?: null,
        );
    }

    private function attachmentFromMessage(WebklexMessage $message, string $attachmentId): MessageAttachment
    {
        $index = 0;

        foreach ($message->getAttachments() as $attachment) {
            if (!$attachment instanceof WebklexAttachment) {
                continue;
            }

            $index++;
            $metadata = $this->attachmentFromWebklex($attachment, $index, false);
            if (hash_equals($metadata->id, $attachmentId)) {
                return $this->attachmentFromWebklex($attachment, $index, true);
            }
        }

        throw new MailboxUnavailableException('Attachment not found.');
    }

    /**
     * @return list<MessageAttachment>
     */
    private function attachmentsFromMessage(WebklexMessage $message, bool $includeContent): array
    {
        $attachments = [];
        $index = 0;

        foreach ($message->getAttachments() as $attachment) {
            if (!$attachment instanceof WebklexAttachment) {
                continue;
            }

            $index++;
            $attachments[] = $this->attachmentFromWebklex($attachment, $index, $includeContent);
        }

        return $attachments;
    }

    private function attachmentFromWebklex(
        WebklexAttachment $attachment,
        int $index,
        bool $includeContent,
    ): MessageAttachment {
        $id = hash('sha256', (string) ($attachment->getHash() ?: $attachment->getPartNumber() ?: $index));
        $name = $attachment->getName() ?: ($attachment->filename ?? null) ?: 'attachment-' . $index;
        $bytes = max(0, (int) $attachment->getSize());
        $content = '';

        if ($includeContent && $bytes <= $this->config->int('mail.max_attachment_bytes')) {
            $content = (string) $attachment->getContent();
        }

        return new MessageAttachment(
            $id,
            basename((string) $name),
            (string) ($attachment->getMimeType() ?: $attachment->getContentType() ?: 'application/octet-stream'),
            $bytes,
            $content,
            strtolower((string) $attachment->getDisposition()) === 'inline',
            (string) $attachment->getId() ?: null,
        );
    }

    private function attribute(WebklexMessage $message, string $name, string $default = ''): string
    {
        $value = $message->get($name);
        if (is_object($value) && method_exists($value, 'toString')) {
            $value = $value->toString();
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    private function unreadCount(WebklexFolder $folder): int
    {
        $status = $folder->status ?? [];
        $value = $status['unseen'] ?? $status['UNSEEN'] ?? 0;
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<string>
     */
    private function specialUse(WebklexFolder $folder): array
    {
        $uses = [];
        foreach (['Inbox', 'Sent', 'Drafts', 'Trash', 'Junk', 'Archive'] as $candidate) {
            $folderPath = strtolower($folder->path);
            if (strcasecmp($folder->name, $candidate) === 0 || str_ends_with($folderPath, strtolower($candidate))) {
                $uses[] = strtolower($candidate);
            }
        }

        return $uses;
    }

    /**
     * @return list<string>
     */
    private function rawCapabilities(Client $client): array
    {
        try {
            return array_values(array_map('strval', $client->getConnection()->getCapabilities()->validatedData()));
        } catch (Throwable) {
            return [];
        }
    }

    private function supportsCapability(Client $client, string $capability): bool
    {
        return in_array(strtoupper($capability), array_map('strtoupper', $this->rawCapabilities($client)), true);
    }

    private function aclConnection(Client $client): ImapProtocol
    {
        if (!$this->supportsCapability($client, 'ACL')) {
            throw new MailboxUnavailableException('IMAP ACL is not available for this mailbox.');
        }

        $connection = $client->getConnection();
        if (!$connection instanceof ImapProtocol) {
            throw new MailboxUnavailableException('IMAP ACL is not available for this connection.');
        }

        return $connection;
    }

    /**
     * @param array<int, mixed> $rows
     */
    private function rightsFromMyRightsResponse(array $rows): MailboxRights
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $parts = array_values($row);
            foreach ($parts as $index => $part) {
                if (!is_scalar($part) || strtoupper((string) $part) !== 'MYRIGHTS') {
                    continue;
                }

                $rights = $parts[$index + 2] ?? null;
                if (is_scalar($rights) && (string) $rights !== '') {
                    return new MailboxRights((string) $rights);
                }
            }
        }

        return new MailboxRights();
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<MailboxAclEntry>
     */
    private function aclFromGetAclResponse(array $rows): array
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $parts = array_values($row);
            foreach ($parts as $index => $part) {
                if (!is_scalar($part) || strtoupper((string) $part) !== 'ACL') {
                    continue;
                }

                return $this->aclEntriesFromParts(array_slice($parts, $index + 2));
            }
        }

        return [];
    }

    /**
     * @param list<mixed> $parts
     * @return list<MailboxAclEntry>
     */
    private function aclEntriesFromParts(array $parts): array
    {
        $entries = [];

        for ($index = 0; $index + 1 < count($parts); $index += 2) {
            $identifier = $parts[$index];
            $rights = $parts[$index + 1];

            if (!is_scalar($identifier) || !is_scalar($rights) || trim((string) $identifier) === '') {
                continue;
            }

            $entries[] = new MailboxAclEntry((string) $identifier, new MailboxRights((string) $rights));
        }

        return $entries;
    }
}

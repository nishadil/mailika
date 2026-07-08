<?php

declare(strict_types=1);

namespace Mailika\Mail;

use Mailika\Auth\MailboxCredentials;

/**
 * @phpstan-type FixtureAttachment array{
 *     id:string,
 *     filename:string,
 *     content_type:string,
 *     bytes:int,
 *     content:string,
 *     inline:bool,
 *     content_id:string|null
 * }
 * @phpstan-type FixtureFolder array{
 *     name:string,
 *     display_name:string,
 *     delimiter:string,
 *     selectable:bool,
 *     has_children:bool,
 *     special_use:list<string>
 * }
 * @phpstan-type FixtureMessage array{
 *     id:string,
 *     folder:string,
 *     from:string,
 *     to:string,
 *     cc:string,
 *     reply_to:string,
 *     subject:string,
 *     date:string,
 *     html:string,
 *     text:string,
 *     seen:bool,
 *     flagged:bool,
 *     answered:bool,
 *     deleted:bool,
 *     draft:bool,
 *     thread_id:string|null,
 *     message_id:string,
 *     attachments:list<FixtureAttachment>
 * }
 * @phpstan-type FixtureState array{
 *     folders:array<string, FixtureFolder>,
 *     messages:list<FixtureMessage>,
 *     next_id:int
 * }
 */
final class FixtureMailboxClient implements MailboxClientInterface
{
    private const SESSION_KEY = 'mailika_fixture_mailbox';

    public function authenticate(MailboxCredentials $credentials): void
    {
        $this->state();
    }

    public function folders(MailboxCredentials $credentials): array
    {
        $state = $this->state();
        $folders = [];

        foreach ($state['folders'] as $folder) {
            $folders[] = new MailboxFolder(
                $folder['name'],
                $folder['display_name'],
                $folder['delimiter'],
                $this->unreadCount($state, $folder['name']),
                $folder['selectable'],
                $folder['has_children'],
                $folder['special_use'],
            );
        }

        return $folders;
    }

    public function messages(MailboxCredentials $credentials, string $folder, int $limit = 50): array
    {
        return $this->search($credentials, new MessageSearchCriteria($folder, '', 1, $limit));
    }

    public function search(MailboxCredentials $credentials, MessageSearchCriteria $criteria): array
    {
        $messages = [];

        foreach ($this->state()['messages'] as $message) {
            if ($message['folder'] !== $criteria->folder || $message['deleted']) {
                continue;
            }

            if (!$this->messageMatches($message, $criteria)) {
                continue;
            }

            $messages[] = $this->envelope($message);
        }

        return array_slice($messages, ($criteria->page - 1) * $criteria->limit, $criteria->limit);
    }

    public function message(MailboxCredentials $credentials, string $folder, string $id): Message
    {
        $message = $this->messageById($folder, $id);

        return new Message(
            $message['id'],
            $message['from'],
            $message['to'],
            $message['subject'],
            $message['date'],
            $message['html'],
            $message['text'],
            array_map(
                static fn (array $attachment): Attachment => new Attachment(
                    $attachment['filename'],
                    $attachment['content_type'],
                    $attachment['bytes'],
                    $attachment['id'],
                    $attachment['inline'],
                    $attachment['content_id'],
                ),
                $message['attachments'],
            ),
            $message['cc'],
            $message['reply_to'],
            trim($message['message_id'], '<>'),
        );
    }

    public function attachment(
        MailboxCredentials $credentials,
        string $folder,
        string $messageId,
        string $attachmentId,
    ): MessageAttachment {
        $message = $this->messageById($folder, $messageId);

        foreach ($message['attachments'] as $attachment) {
            if (hash_equals($attachment['id'], $attachmentId)) {
                return new MessageAttachment(
                    $attachment['id'],
                    $attachment['filename'],
                    $attachment['content_type'],
                    $attachment['bytes'],
                    $attachment['content'],
                    $attachment['inline'],
                    $attachment['content_id'],
                );
            }
        }

        throw new MailboxUnavailableException('Attachment not found.');
    }

    public function markSeen(MailboxCredentials $credentials, string $folder, string $id, bool $seen): void
    {
        $this->updateMessage($folder, $id, 'seen', $seen);
    }

    public function flag(MailboxCredentials $credentials, string $folder, string $id, bool $flagged): void
    {
        $this->updateMessage($folder, $id, 'flagged', $flagged);
    }

    public function move(MailboxCredentials $credentials, string $folder, string $id, string $targetFolder): void
    {
        $this->assertFolder($targetFolder);
        $this->updateMessage($folder, $id, 'folder', $targetFolder);
    }

    public function copy(MailboxCredentials $credentials, string $folder, string $id, string $targetFolder): void
    {
        $this->assertFolder($targetFolder);
        $state = $this->state();

        foreach ($state['messages'] as $message) {
            if ($message['folder'] === $folder && $message['id'] === $id && !$message['deleted']) {
                $message['id'] = (string) $state['next_id'];
                $message['folder'] = $targetFolder;
                $state['next_id']++;
                $state['messages'][] = $message;
                $this->persist($state);
                return;
            }
        }

        throw new MailboxUnavailableException('Message not found.');
    }

    public function delete(MailboxCredentials $credentials, string $folder, string $id): void
    {
        $this->updateMessage($folder, $id, 'deleted', true);
    }

    public function createFolder(MailboxCredentials $credentials, string $folder): void
    {
        $state = $this->state();
        if (isset($state['folders'][$folder])) {
            return;
        }

        $state['folders'][$folder] = $this->folder($folder, $folder);
        $this->persist($state);
    }

    public function renameFolder(MailboxCredentials $credentials, string $folder, string $newName): void
    {
        $state = $this->state();
        if (!isset($state['folders'][$folder])) {
            throw new MailboxUnavailableException('Mailbox folder not found.');
        }

        $state['folders'][$newName] = $this->folder($newName, $newName);
        unset($state['folders'][$folder]);

        foreach ($state['messages'] as $index => $message) {
            if ($message['folder'] === $folder) {
                $state['messages'][$index]['folder'] = $newName;
            }
        }

        $this->persist($state);
    }

    public function deleteFolder(MailboxCredentials $credentials, string $folder): void
    {
        $state = $this->state();
        if (!isset($state['folders'][$folder])) {
            return;
        }

        unset($state['folders'][$folder]);
        foreach ($state['messages'] as $index => $message) {
            if ($message['folder'] === $folder) {
                unset($state['messages'][$index]);
            }
        }

        $state['messages'] = array_values($state['messages']);
        $this->persist($state);
    }

    public function capabilities(MailboxCredentials $credentials): MailboxCapabilities
    {
        return new MailboxCapabilities(true, true, false, false, true, true, ['FIXTURE', 'MOVE', 'SORT', 'THREAD']);
    }

    public function mailboxRights(MailboxCredentials $credentials, string $folder): MailboxRights
    {
        return new MailboxRights();
    }

    public function mailboxAcl(MailboxCredentials $credentials, string $folder): array
    {
        return [];
    }

    public function setMailboxAcl(
        MailboxCredentials $credentials,
        string $folder,
        string $identifier,
        MailboxRights $rights,
    ): void {
        throw new MailboxUnavailableException('ACL editing is not available in the fixture mailbox.');
    }

    public function deleteMailboxAcl(MailboxCredentials $credentials, string $folder, string $identifier): void
    {
        throw new MailboxUnavailableException('ACL editing is not available in the fixture mailbox.');
    }

    public function quota(MailboxCredentials $credentials, string $folder = 'INBOX'): MailboxQuota
    {
        return new MailboxQuota(12_582_912, 1_073_741_824);
    }

    /**
     * @return FixtureState
     */
    private function state(): array
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($state) && isset($state['folders'], $state['messages'], $state['next_id'])) {
            /** @var FixtureState $state */
            return $state;
        }

        $state = $this->initialState();
        $this->persist($state);

        return $state;
    }

    /**
     * @param FixtureState $state
     */
    private function persist(array $state): void
    {
        $_SESSION[self::SESSION_KEY] = $state;
    }

    /**
     * @return FixtureState
     */
    private function initialState(): array
    {
        $logo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMB/akJHn8AAAAASUVORK5CYII=',
            true,
        );
        $logo = is_string($logo) ? $logo : '';

        return [
            'folders' => [
                'INBOX' => $this->folder('INBOX', 'Inbox', ['inbox']),
                'Archive' => $this->folder('Archive', 'Archive', ['archive']),
                'Sent' => $this->folder('Sent', 'Sent', ['sent']),
                'Drafts' => $this->folder('Drafts', 'Drafts', ['drafts']),
                'Trash' => $this->folder('Trash', 'Trash', ['trash']),
            ],
            'messages' => [
                [
                    'id' => '1001',
                    'folder' => 'INBOX',
                    'from' => 'Mailika Team <opensource@nishadil.dev>',
                    'to' => 'smoke@example.com',
                    'cc' => '',
                    'reply_to' => 'opensource@nishadil.dev',
                    'subject' => 'Welcome to Mailika',
                    'date' => 'Tue, 7 Jul 2026 09:30:00 +0000',
                    'html' => '<p>Welcome to <strong>Mailika</strong>.</p>'
                        . '<p>This fixture message includes sanitized HTML and an inline logo.</p>'
                        . '<script>alert("blocked")</script>'
                        . '<img src="https://tracker.example/pixel.png" alt="remote tracker">'
                        . '<img src="cid:logo@mailika" alt="Mailika logo">',
                    'text' => 'Welcome to Mailika.',
                    'seen' => false,
                    'flagged' => false,
                    'answered' => false,
                    'deleted' => false,
                    'draft' => false,
                    'thread_id' => 'welcome-thread',
                    'message_id' => '<welcome.fixture@mailika>',
                    'attachments' => [
                        [
                            'id' => 'logo',
                            'filename' => 'mailika-logo.png',
                            'content_type' => 'image/png',
                            'bytes' => strlen($logo),
                            'content' => $logo,
                            'inline' => true,
                            'content_id' => 'logo@mailika',
                        ],
                    ],
                ],
                [
                    'id' => '1002',
                    'folder' => 'INBOX',
                    'from' => 'Reports <reports@example.com>',
                    'to' => 'smoke@example.com',
                    'cc' => 'team@example.com',
                    'reply_to' => '',
                    'subject' => 'Quarterly security report',
                    'date' => 'Tue, 7 Jul 2026 08:15:00 +0000',
                    'html' => '<p>The quarterly security report is attached.</p>',
                    'text' => 'The quarterly security report is attached.',
                    'seen' => true,
                    'flagged' => true,
                    'answered' => false,
                    'deleted' => false,
                    'draft' => false,
                    'thread_id' => 'security-report',
                    'message_id' => '<report.fixture@mailika>',
                    'attachments' => [
                        [
                            'id' => 'report',
                            'filename' => 'security-report.txt',
                            'content_type' => 'text/plain',
                            'bytes' => 30,
                            'content' => 'Quarterly security report data',
                            'inline' => false,
                            'content_id' => null,
                        ],
                    ],
                ],
                [
                    'id' => '1003',
                    'folder' => 'INBOX',
                    'from' => 'Roundcube Admin <admin@example.com>',
                    'to' => 'smoke@example.com',
                    'cc' => '',
                    'reply_to' => '',
                    'subject' => 'Re: Welcome to Mailika',
                    'date' => 'Tue, 7 Jul 2026 10:00:00 +0000',
                    'html' => '<p>Threaded replies stay grouped when the preference is enabled.</p>',
                    'text' => 'Threaded replies stay grouped when the preference is enabled.',
                    'seen' => true,
                    'flagged' => false,
                    'answered' => true,
                    'deleted' => false,
                    'draft' => false,
                    'thread_id' => 'welcome-thread',
                    'message_id' => '<welcome-reply.fixture@mailika>',
                    'attachments' => [],
                ],
            ],
            'next_id' => 2000,
        ];
    }

    /**
     * @param list<string> $specialUse
     * @return FixtureFolder
     */
    private function folder(string $name, string $displayName, array $specialUse = []): array
    {
        return [
            'name' => $name,
            'display_name' => $displayName,
            'delimiter' => '/',
            'selectable' => true,
            'has_children' => false,
            'special_use' => $specialUse,
        ];
    }

    /**
     * @param FixtureState $state
     */
    private function unreadCount(array $state, string $folder): int
    {
        $count = 0;
        foreach ($state['messages'] as $message) {
            if ($message['folder'] === $folder && !$message['seen'] && !$message['deleted']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param FixtureMessage $message
     */
    private function envelope(array $message): MessageEnvelope
    {
        return new MessageEnvelope(
            $message['id'],
            $message['from'],
            $message['subject'],
            $message['date'],
            $message['seen'],
            $message['attachments'] !== [],
            $message['flagged'],
            $message['answered'],
            $message['deleted'],
            $message['draft'],
            $message['thread_id'],
        );
    }

    /**
     * @param FixtureMessage $message
     */
    private function searchText(array $message): string
    {
        return implode(' ', [
            $message['from'],
            $message['to'],
            $message['cc'],
            $message['subject'],
            $message['text'],
            strip_tags($message['html']),
        ]);
    }

    /**
     * @param FixtureMessage $message
     */
    private function messageMatches(array $message, MessageSearchCriteria $criteria): bool
    {
        if ($criteria->unseenOnly && $message['seen']) {
            return false;
        }

        if ($criteria->flaggedOnly && !$message['flagged']) {
            return false;
        }

        if ($criteria->query !== '' && !$this->contains($this->searchText($message), $criteria->query)) {
            return false;
        }

        if ($criteria->from !== null && $criteria->from !== '' && !$this->contains($message['from'], $criteria->from)) {
            return false;
        }

        if ($criteria->to !== null && $criteria->to !== '') {
            $recipients = trim($message['to'] . ' ' . $message['cc']);
            if (!$this->contains($recipients, $criteria->to)) {
                return false;
            }
        }

        return $criteria->subject === null
            || $criteria->subject === ''
            || $this->contains($message['subject'], $criteria->subject);
    }

    private function contains(string $haystack, string $needle): bool
    {
        return str_contains(mb_strtolower($haystack), mb_strtolower($needle));
    }

    /**
     * @return FixtureMessage
     */
    private function messageById(string $folder, string $id): array
    {
        foreach ($this->state()['messages'] as $message) {
            if ($message['folder'] === $folder && $message['id'] === $id && !$message['deleted']) {
                return $message;
            }
        }

        throw new MailboxUnavailableException('Message not found.');
    }

    private function assertFolder(string $folder): void
    {
        if (!isset($this->state()['folders'][$folder])) {
            throw new MailboxUnavailableException('Mailbox folder not found.');
        }
    }

    private function updateMessage(string $folder, string $id, string $field, bool|string $value): void
    {
        $state = $this->state();

        foreach ($state['messages'] as $index => $message) {
            if ($message['folder'] !== $folder || $message['id'] !== $id || $message['deleted']) {
                continue;
            }

            if ($field === 'seen' && is_bool($value)) {
                $state['messages'][$index]['seen'] = $value;
            } elseif ($field === 'flagged' && is_bool($value)) {
                $state['messages'][$index]['flagged'] = $value;
            } elseif ($field === 'deleted' && is_bool($value)) {
                $state['messages'][$index]['deleted'] = $value;
            } elseif ($field === 'folder' && is_string($value)) {
                $state['messages'][$index]['folder'] = $value;
            }

            $this->persist($state);
            return;
        }

        throw new MailboxUnavailableException('Message not found.');
    }
}

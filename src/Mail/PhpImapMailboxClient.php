<?php

declare(strict_types=1);

namespace Mailika\Mail;

use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Throwable;

final readonly class PhpImapMailboxClient implements MailboxClientInterface
{
    public function __construct(
        private Config $config,
        private PhpImapSearchQueryCompiler $searchQueryCompiler = new PhpImapSearchQueryCompiler(),
        private PhpImapCapabilityParser $capabilityParser = new PhpImapCapabilityParser(),
        private PhpImapQuotaParser $quotaParser = new PhpImapQuotaParser(),
        private PhpImapAclParser $aclParser = new PhpImapAclParser(),
    ) {
    }

    public function authenticate(MailboxCredentials $credentials): void
    {
        $stream = $this->open($credentials, 'INBOX', false);

        try {
            // Opening the mailbox is the authentication check for ext-imap.
        } finally {
            $this->close($stream);
        }
    }

    public function folders(MailboxCredentials $credentials): array
    {
        $stream = $this->open($credentials, 'INBOX', false);
        try {
            $imapList = 'imap_list';
            $folders = $imapList($stream, $this->mailboxPrefix($credentials), '*');
        } finally {
            $this->close($stream);
        }

        if (!is_array($folders)) {
            return [new MailboxFolder('INBOX', 'Inbox')];
        }

        return array_map(
            fn (string $folder): MailboxFolder => new MailboxFolder(
                $this->folderName($credentials, $folder),
                $this->folderDisplayName($folder),
            ),
            array_values($folders),
        );
    }

    public function messages(MailboxCredentials $credentials, string $folder, int $limit = 50): array
    {
        $stream = $this->open($credentials, $folder, true);
        try {
            $numMsg = 'imap_num_msg';
            $count = (int) $numMsg($stream);
            $messages = [];

            for ($i = $count; $i >= max(1, $count - $limit + 1); $i--) {
                $messages[] = $this->envelopeFromSequence($stream, $i);
            }

            return $messages;
        } finally {
            $this->close($stream);
        }
    }

    public function search(MailboxCredentials $credentials, MessageSearchCriteria $criteria): array
    {
        $stream = $this->open($credentials, $criteria->folder, true);
        try {
            $search = 'imap_search';
            $ids = $search($stream, $this->searchQueryCompiler->compile($criteria), 0);

            if (!is_array($ids)) {
                return [];
            }

            $sequences = array_values(array_filter(
                array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids),
                static fn (int $id): bool => $id > 0,
            ));
            rsort($sequences, SORT_NUMERIC);
            $sequences = array_slice(
                $sequences,
                max(0, $criteria->page - 1) * $criteria->limit,
                $criteria->limit,
            );

            $messages = [];
            foreach ($sequences as $sequence) {
                $messages[] = $this->envelopeFromSequence($stream, $sequence);
            }

            return $messages;
        } finally {
            $this->close($stream);
        }
    }

    public function message(MailboxCredentials $credentials, string $folder, string $id): Message
    {
        $stream = $this->open($credentials, $folder, true);
        try {
            $headerInfo = 'imap_headerinfo';
            $body = 'imap_body';
            $header = $headerInfo($stream, (int) $id);
            $rawBody = (string) $body($stream, (int) $id, FT_PEEK);

            return new Message(
                $id,
                $this->addressFromHeader($header),
                $credentials->email,
                $this->decodeMime((string) ($header->subject ?? '(no subject)')),
                (string) ($header->date ?? ''),
                nl2br(htmlspecialchars($rawBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                $rawBody,
                [],
                $this->addressListFromHeader($header, 'cc'),
                $this->addressListFromHeader($header, 'reply_to'),
                isset($header->message_id) ? trim((string) $header->message_id, '<>') : null,
            );
        } finally {
            $this->close($stream);
        }
    }

    public function attachment(
        MailboxCredentials $credentials,
        string $folder,
        string $messageId,
        string $attachmentId,
    ): MessageAttachment {
        throw new MailboxUnavailableException('Attachment streaming is not available in the legacy IMAP adapter.');
    }

    public function markSeen(MailboxCredentials $credentials, string $folder, string $id, bool $seen): void
    {
        $stream = $this->open($credentials, $folder, false);
        try {
            $flag = $seen ? 'imap_setflag_full' : 'imap_clearflag_full';
            $flag($stream, $id, '\\Seen');
        } finally {
            $this->close($stream);
        }
    }

    public function flag(MailboxCredentials $credentials, string $folder, string $id, bool $flagged): void
    {
        $stream = $this->open($credentials, $folder, false);
        try {
            $flag = $flagged ? 'imap_setflag_full' : 'imap_clearflag_full';
            $flag($stream, $id, '\\Flagged');
        } finally {
            $this->close($stream);
        }
    }

    public function move(MailboxCredentials $credentials, string $folder, string $id, string $targetFolder): void
    {
        $stream = $this->open($credentials, $folder, false);
        try {
            $move = 'imap_mail_move';
            $move($stream, $id, $targetFolder);
        } finally {
            $this->close($stream);
        }
    }

    public function copy(MailboxCredentials $credentials, string $folder, string $id, string $targetFolder): void
    {
        $stream = $this->open($credentials, $folder, false);
        try {
            $copy = 'imap_mail_copy';
            $copy($stream, $id, $targetFolder);
        } finally {
            $this->close($stream);
        }
    }

    public function delete(MailboxCredentials $credentials, string $folder, string $id): void
    {
        $stream = $this->open($credentials, $folder, false);
        try {
            $delete = 'imap_delete';
            $expunge = 'imap_expunge';
            $delete($stream, $id);
            $expunge($stream);
        } finally {
            $this->close($stream);
        }
    }

    public function createFolder(MailboxCredentials $credentials, string $folder): void
    {
        $stream = $this->open($credentials, 'INBOX', false);
        try {
            $create = 'imap_createmailbox';
            $create($stream, $this->mailboxPrefix($credentials) . $folder);
        } finally {
            $this->close($stream);
        }
    }

    public function renameFolder(MailboxCredentials $credentials, string $folder, string $newName): void
    {
        $stream = $this->open($credentials, 'INBOX', false);
        try {
            $rename = 'imap_renamemailbox';
            $rename(
                $stream,
                $this->mailboxPrefix($credentials) . $folder,
                $this->mailboxPrefix($credentials) . $newName,
            );
        } finally {
            $this->close($stream);
        }
    }

    public function deleteFolder(MailboxCredentials $credentials, string $folder): void
    {
        $stream = $this->open($credentials, 'INBOX', false);
        try {
            $delete = 'imap_deletemailbox';
            $delete($stream, $this->mailboxPrefix($credentials) . $folder);
        } finally {
            $this->close($stream);
        }
    }

    public function capabilities(MailboxCredentials $credentials): MailboxCapabilities
    {
        $quota = $this->quota($credentials);
        $acl = $this->mailboxAcl($credentials, 'INBOX');
        $raw = [];
        if ($quota->usedBytes !== null || $quota->limitBytes !== null) {
            $raw[] = 'QUOTA';
        }
        if ($acl !== []) {
            $raw[] = 'ACL';
        }

        return $this->capabilityParser->parse($raw);
    }

    public function mailboxRights(MailboxCredentials $credentials, string $folder): MailboxRights
    {
        return $this->aclParser->rightsFor($this->mailboxAcl($credentials, $folder), $credentials->email);
    }

    public function mailboxAcl(MailboxCredentials $credentials, string $folder): array
    {
        if (!function_exists('imap_getacl')) {
            return [];
        }

        $stream = $this->open($credentials, $folder, true);
        try {
            $getAcl = 'imap_getacl';

            return $this->aclParser->parse($getAcl($stream, $this->mailbox($credentials, $folder)));
        } catch (Throwable) {
            return [];
        } finally {
            $this->close($stream);
        }
    }

    public function setMailboxAcl(
        MailboxCredentials $credentials,
        string $folder,
        string $identifier,
        MailboxRights $rights,
    ): void {
        throw new MailboxUnavailableException('ACL editing is not available in the legacy IMAP adapter.');
    }

    public function deleteMailboxAcl(MailboxCredentials $credentials, string $folder, string $identifier): void
    {
        throw new MailboxUnavailableException('ACL editing is not available in the legacy IMAP adapter.');
    }

    public function quota(MailboxCredentials $credentials, string $folder = 'INBOX'): MailboxQuota
    {
        if (!function_exists('imap_get_quotaroot')) {
            return new MailboxQuota();
        }

        $stream = $this->open($credentials, $folder, true);
        try {
            $quota = 'imap_get_quotaroot';

            return $this->quotaParser->parse($quota($stream, $folder));
        } catch (Throwable) {
            return new MailboxQuota();
        } finally {
            $this->close($stream);
        }
    }

    /**
     * @return mixed
     */
    private function open(MailboxCredentials $credentials, string $folder, bool $readOnly)
    {
        if (!function_exists('imap_open')) {
            throw new MailboxUnavailableException('The PHP IMAP extension is not installed.');
        }

        $open = 'imap_open';
        $flags = $readOnly && defined('OP_READONLY') ? constant('OP_READONLY') : 0;
        $stream = $open(
            $this->mailbox($credentials, $folder),
            $credentials->email,
            $credentials->password,
            $flags,
            1,
            ['DISABLE_AUTHENTICATOR' => 'GSSAPI'],
        );

        if ($stream === false) {
            throw new MailboxUnavailableException('Unable to connect to the IMAP mailbox.');
        }

        return $stream;
    }

    /**
     * @param mixed $stream
     */
    private function close($stream): void
    {
        if (function_exists('imap_close')) {
            $close = 'imap_close';
            $close($stream);
        }
    }

    private function mailbox(MailboxCredentials $credentials, string $folder): string
    {
        return $this->mailboxPrefix($credentials) . $folder;
    }

    private function mailboxPrefix(MailboxCredentials $credentials): string
    {
        $flags = ['/imap'];
        $flags[] = $credentials->imapTls ? '/ssl' : '/notls';

        if ($this->config->bool('mail.require_tls', true)) {
            $flags[] = '/validate-cert';
        }

        return sprintf('{%s:%d%s}', $credentials->imapHost, $credentials->imapPort, implode('', $flags));
    }

    private function folderName(MailboxCredentials $credentials, string $folder): string
    {
        return str_replace($this->mailboxPrefix($credentials), '', $folder);
    }

    private function folderDisplayName(string $folder): string
    {
        $parts = explode('}', $folder);
        return end($parts) ?: 'INBOX';
    }

    private function decodeMime(string $value): string
    {
        if (!function_exists('imap_mime_header_decode')) {
            return $value;
        }

        $decode = 'imap_mime_header_decode';
        $decoded = $decode($value);

        if (!is_array($decoded)) {
            return $value;
        }

        return implode('', array_map(static fn (object $part): string => (string) ($part->text ?? ''), $decoded));
    }

    /**
     * @param mixed $stream
     */
    private function envelopeFromSequence($stream, int $sequence): MessageEnvelope
    {
        $headerInfo = 'imap_headerinfo';
        $fetchOverview = 'imap_fetch_overview';
        $overview = $fetchOverview($stream, (string) $sequence, 0);
        $header = $headerInfo($stream, $sequence);
        $item = is_array($overview) && isset($overview[0]) ? $overview[0] : null;

        return new MessageEnvelope(
            (string) $sequence,
            $this->addressFromHeader($header),
            $this->decodeMime((string) ($item->subject ?? '(no subject)')),
            (string) ($item->date ?? ''),
            isset($item->seen) && (bool) $item->seen,
            false,
            isset($item->flagged) && (bool) $item->flagged,
            isset($item->answered) && (bool) $item->answered,
            isset($item->deleted) && (bool) $item->deleted,
            isset($item->draft) && (bool) $item->draft,
            null,
        );
    }

    private function addressFromHeader(mixed $header): string
    {
        $addresses = $this->addressesFromHeader($header, 'from');

        return $addresses[0] ?? '';
    }

    private function addressListFromHeader(mixed $header, string $field): string
    {
        return implode(', ', $this->addressesFromHeader($header, $field));
    }

    /**
     * @return list<string>
     */
    private function addressesFromHeader(mixed $header, string $field): array
    {
        if (!is_object($header) || !isset($header->{$field}) || !is_array($header->{$field})) {
            return [];
        }

        $addresses = [];
        foreach ($header->{$field} as $address) {
            if (!is_object($address)) {
                continue;
            }

            $mailbox = (string) ($address->mailbox ?? '');
            $host = (string) ($address->host ?? '');
            if ($mailbox !== '' && $host !== '') {
                $addresses[] = $mailbox . '@' . $host;
            }
        }

        return $addresses;
    }
}

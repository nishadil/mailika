<?php

declare(strict_types=1);

namespace Mailika\Mail;

use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;

final readonly class PhpImapMailboxClient implements MailboxClientInterface
{
    public function __construct(private Config $config)
    {
    }

    public function authenticate(MailboxCredentials $credentials): void
    {
        $stream = $this->open($credentials, 'INBOX', false);
        $this->close($stream);
    }

    public function folders(MailboxCredentials $credentials): array
    {
        $stream = $this->open($credentials, 'INBOX', false);
        $imapList = 'imap_list';
        $folders = $imapList($stream, $this->mailboxPrefix($credentials), '*');
        $this->close($stream);

        if (!is_array($folders)) {
            return [new Folder('INBOX', 'Inbox')];
        }

        return array_map(
            fn (string $folder): Folder => new Folder(
                $this->folderName($credentials, $folder),
                $this->folderDisplayName($folder),
            ),
            array_values($folders),
        );
    }

    public function messages(MailboxCredentials $credentials, string $folder, int $limit = 50): array
    {
        $stream = $this->open($credentials, $folder, true);
        $numMsg = 'imap_num_msg';
        $headerInfo = 'imap_headerinfo';
        $fetchOverview = 'imap_fetch_overview';
        $count = (int) $numMsg($stream);
        $messages = [];

        for ($i = $count; $i >= max(1, $count - $limit + 1); $i--) {
            $overview = $fetchOverview($stream, (string) $i, 0);
            $header = $headerInfo($stream, $i);
            $item = is_array($overview) && isset($overview[0]) ? $overview[0] : null;

            $messages[] = new MessageSummary(
                (string) $i,
                $this->addressFromHeader($header),
                $this->decodeMime((string) ($item->subject ?? '(no subject)')),
                (string) ($item->date ?? ''),
                isset($item->seen) && (bool) $item->seen,
                false,
            );
        }

        $this->close($stream);
        return $messages;
    }

    public function message(MailboxCredentials $credentials, string $folder, string $id): Message
    {
        $stream = $this->open($credentials, $folder, true);
        $headerInfo = 'imap_headerinfo';
        $body = 'imap_body';
        $header = $headerInfo($stream, (int) $id);
        $rawBody = (string) $body($stream, (int) $id, FT_PEEK);
        $this->close($stream);

        return new Message(
            $id,
            $this->addressFromHeader($header),
            $credentials->email,
            $this->decodeMime((string) ($header->subject ?? '(no subject)')),
            (string) ($header->date ?? ''),
            nl2br(htmlspecialchars($rawBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            $rawBody,
        );
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

    private function addressFromHeader(mixed $header): string
    {
        if (!is_object($header) || !isset($header->from) || !is_array($header->from) || !isset($header->from[0])) {
            return '';
        }

        $from = $header->from[0];
        if (!is_object($from)) {
            return '';
        }

        $mailbox = (string) ($from->mailbox ?? '');
        $host = (string) ($from->host ?? '');
        return $mailbox !== '' && $host !== '' ? $mailbox . '@' . $host : '';
    }
}

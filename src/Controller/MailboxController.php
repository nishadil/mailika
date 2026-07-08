<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Auth\CredentialVault;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Mail\MailboxCapabilities;
use Mailika\Mail\MailboxClientInterface;
use Mailika\Mail\MailboxQuota;
use Mailika\Mail\MailboxRights;
use Mailika\Mail\Message;
use Mailika\Mail\MessageMetadataCacheRepositoryInterface;
use Mailika\Mail\MessageSearchCriteria;
use Mailika\Mail\MailAccountProfileRepositoryInterface;
use Mailika\Mail\SavedSearch;
use Mailika\Mail\SavedSearchRepositoryInterface;
use Mailika\Preferences\PreferencesRepositoryInterface;
use Mailika\Security\CsrfTokenManager;
use Mailika\Security\HtmlSanitizer;
use Mailika\Support\View;
use Throwable;

final readonly class MailboxController
{
    public function __construct(
        private View $view,
        private CsrfTokenManager $csrf,
        private CredentialVault $vault,
        private MailboxClientInterface $mailboxClient,
        private HtmlSanitizer $sanitizer,
        private SavedSearchRepositoryInterface $savedSearches,
        private MailAccountProfileRepositoryInterface $accountProfiles,
        private MessageMetadataCacheRepositoryInterface $messageMetadataCache,
        private PreferencesRepositoryInterface $preferences,
    ) {
    }

    public function index(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        $folder = $request->input('folder', 'INBOX');
        $warning = null;
        $folders = [];
        $messages = [];
        $mailboxStatus = 'live';
        $quota = new MailboxQuota();
        $capabilities = new MailboxCapabilities();
        $mailboxRights = new MailboxRights();
        $mailboxAcl = [];
        $page = max(1, $request->intInput('page', 1));
        $preferences = $this->preferences->get($credentials->email);
        $criteria = $this->searchCriteria($request, $folder, $page, $preferences->messagesPerPage);
        $query = $criteria->query;
        $canonicalSearchQuery = $criteria->canonicalQuery();
        $savedSearches = $this->savedSearches->listForMailbox($credentials->email);
        $accountProfiles = $this->accountProfiles->listForMailbox($credentials->email);

        try {
            $folders = $this->mailboxClient->folders($credentials);
            $messages = $this->mailboxClient->search($credentials, $criteria);
            $this->storeCache($credentials->email, $folder, $messages);
            $quota = $this->quota($credentials, $folder);
            $capabilities = $this->capabilities($credentials);
            if ($capabilities->acl) {
                $mailboxRights = $this->mailboxRights($credentials, $folder);
                $mailboxAcl = $this->mailboxAcl($credentials, $folder);
            }
        } catch (Throwable) {
            $warning = 'Mailbox temporarily unavailable. Showing cached metadata when available.';
            $messages = $this->messageMetadataCache->list(
                $credentials->email,
                $folder,
                $preferences->messagesPerPage,
                $page,
                $query,
                $criteria,
            );
            $mailboxStatus = $messages !== [] ? 'cached' : 'unavailable';
        }

        return new Response($this->view->render('mailbox/index', [
            'title' => 'Mailbox',
            'csrfToken' => $this->csrf->token(),
            'credentials' => $credentials,
            'folder' => $folder,
            'query' => $query,
            'canonicalSearchQuery' => $canonicalSearchQuery,
            'searchCriteria' => $criteria,
            'page' => $page,
            'folders' => $folders,
            'messages' => $messages,
            'mailboxStatus' => $mailboxStatus,
            'quota' => $quota,
            'capabilities' => $capabilities,
            'mailboxRights' => $mailboxRights,
            'mailboxAcl' => $mailboxAcl,
            'preferences' => $preferences,
            'savedSearches' => $savedSearches,
            'accountProfiles' => $accountProfiles,
            'warning' => $warning,
        ]));
    }

    /**
     * @param list<\Mailika\Mail\MessageEnvelope> $messages
     */
    private function storeCache(string $mailboxIdentity, string $folder, array $messages): void
    {
        try {
            $this->messageMetadataCache->store($mailboxIdentity, $folder, $messages);
        } catch (Throwable) {
        }
    }

    private function quota(\Mailika\Auth\MailboxCredentials $credentials, string $folder): MailboxQuota
    {
        try {
            return $this->mailboxClient->quota($credentials, $folder);
        } catch (Throwable) {
            return new MailboxQuota();
        }
    }

    private function capabilities(\Mailika\Auth\MailboxCredentials $credentials): MailboxCapabilities
    {
        try {
            return $this->mailboxClient->capabilities($credentials);
        } catch (Throwable) {
            return new MailboxCapabilities();
        }
    }

    private function mailboxRights(\Mailika\Auth\MailboxCredentials $credentials, string $folder): MailboxRights
    {
        try {
            return $this->mailboxClient->mailboxRights($credentials, $folder);
        } catch (Throwable) {
            return new MailboxRights();
        }
    }

    /**
     * @return list<\Mailika\Mail\MailboxAclEntry>
     */
    private function mailboxAcl(\Mailika\Auth\MailboxCredentials $credentials, string $folder): array
    {
        try {
            return $this->mailboxClient->mailboxAcl($credentials, $folder);
        } catch (Throwable) {
            return [];
        }
    }

    public function search(Request $request): Response
    {
        return $this->index($request);
    }

    public function saveSearch(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        $folder = $request->input('folder', 'INBOX');
        $criteria = $this->searchCriteria($request, $folder, 1, 50);
        $query = $criteria->canonicalQuery();
        $name = mb_substr($request->input('name'), 0, 255);

        if ($this->csrf->validate($request->input('_csrf')) && $query !== '' && $name !== '') {
            $this->savedSearches->save($credentials->email, new SavedSearch($name, $folder, $query));
        }

        return Response::redirect($this->mailboxUrl($folder, $query));
    }

    public function deleteSearch(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        $folder = $request->input('folder', 'INBOX');
        $query = $this->searchCriteria($request, $folder, 1, 50)->canonicalQuery();

        if ($this->csrf->validate($request->input('_csrf'))) {
            $id = $request->intInput('id');
            if ($id > 0) {
                $this->savedSearches->deleteForMailbox($credentials->email, $id);
            }
        }

        return Response::redirect($this->mailboxUrl($folder, $query));
    }

    public function message(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        $folder = $request->input('folder', 'INBOX');
        $id = $request->input('id');
        $folders = [];

        try {
            $folders = $this->mailboxClient->folders($credentials);
            $message = $this->mailboxClient->message($credentials, $folder, $id);
        } catch (Throwable) {
            $message = new Message(
                $id,
                '',
                $credentials->email,
                'Message unavailable',
                '',
                '',
                'Mailika could not load this message from the mail server. Try again after the mailbox is available.',
            );
        }

        return new Response($this->view->render('mailbox/message', [
            'title' => $message->subject,
            'csrfToken' => $this->csrf->token(),
            'credentials' => $credentials,
            'folder' => $folder,
            'folders' => $folders,
            'message' => $message,
            'safeHtml' => $this->sanitizer->sanitize(
                $this->rewriteInlineImages($message, $folder),
                $this->preferences->get($credentials->email)->remoteImages,
            ),
        ]));
    }

    private function rewriteInlineImages(Message $message, string $folder): string
    {
        $contentIds = [];
        foreach ($message->attachments as $attachment) {
            if (!$attachment->inline || $attachment->id === null || $attachment->contentId === null) {
                continue;
            }

            if (!str_starts_with(strtolower($attachment->contentType), 'image/')) {
                continue;
            }

            $contentIds[$this->contentId($attachment->contentId)] = '/attachment?folder=' . urlencode($folder)
                . '&message=' . urlencode($message->id)
                . '&attachment=' . urlencode($attachment->id)
                . '&inline=1';
        }

        if ($contentIds === []) {
            return $message->htmlBody;
        }

        return preg_replace_callback(
            '/cid:([^"\'\s>]+)/i',
            function (array $matches) use ($contentIds): string {
                $url = $contentIds[$this->contentId(rawurldecode($matches[1]))] ?? null;
                return $url === null ? $matches[0] : $url;
            },
            $message->htmlBody,
        ) ?? '';
    }

    private function contentId(string $value): string
    {
        return strtolower(trim($value, " <>\t\n\r\0\x0B"));
    }

    private function searchCriteria(Request $request, string $folder, int $page, int $limit): MessageSearchCriteria
    {
        return MessageSearchCriteria::fromRawInput(
            $folder,
            $request->input('q'),
            $page,
            $limit,
            $request->input('from'),
            $request->input('to'),
            $request->input('subject'),
            $request->input('unseen') === '1',
            $request->input('flagged') === '1',
        );
    }

    private function mailboxUrl(string $folder, string $query): string
    {
        return '/mailbox?' . http_build_query([
            'folder' => $folder,
            'q' => $query,
        ]);
    }
}

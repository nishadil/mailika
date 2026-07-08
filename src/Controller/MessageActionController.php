<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\MailboxCredentials;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Mail\MailboxClientInterface;
use Mailika\Mail\MailboxRights;
use Mailika\Mail\MessageMetadataCacheRepositoryInterface;
use Mailika\Security\AttachmentPolicy;
use Mailika\Security\CsrfTokenManager;
use RuntimeException;
use Throwable;

final readonly class MessageActionController
{
    public function __construct(
        private CsrfTokenManager $csrf,
        private CredentialVault $vault,
        private MailboxClientInterface $mailboxClient,
        private AttachmentPolicy $attachmentPolicy,
        private AuditLogger $audit,
        private ?MessageMetadataCacheRepositoryInterface $messageMetadataCache = null,
    ) {
    }

    public function update(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        $folder = $request->input('folder', 'INBOX');
        $ids = $this->messageIds($request);

        if ($this->csrf->validate($request->input('_csrf')) && $ids !== []) {
            $action = $request->input('action');
            $targetFolder = in_array($action, ['copy', 'move'], true)
                ? $this->validTargetFolder($credentials, $folder, $request->input('target_folder'))
                : $request->input('target_folder');
            $archiveFolder = null;
            $succeeded = 0;
            $rights = $this->mailboxRights($credentials, $folder);

            if (!$this->actionAllowedByRights($action, $rights)) {
                $this->auditAction($credentials, $folder, $action, count($ids), 0, $targetFolder, null);
                return Response::redirect($this->redirectUrl($folder, $request));
            }

            try {
                $archiveFolder = $action === 'archive' ? $this->archiveFolder($credentials) : null;
            } catch (Throwable) {
            }

            foreach ($ids as $id) {
                try {
                    $this->applyAction($credentials, $folder, $id, $action, $targetFolder, $archiveFolder);
                    if ($this->actionCanMutate($action, $targetFolder, $archiveFolder)) {
                        $this->updateCacheAfterAction(
                            $credentials,
                            $folder,
                            $id,
                            $action,
                            $targetFolder,
                            $archiveFolder,
                        );
                        $succeeded++;
                    }
                } catch (Throwable) {
                    // Keep message actions fail-closed to a safe redirect.
                }
            }

            $this->auditAction($credentials, $folder, $action, count($ids), $succeeded, $targetFolder, $archiveFolder);
        }

        return Response::redirect($this->redirectUrl($folder, $request));
    }

    public function attachment(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        $folder = $request->input('folder', 'INBOX');
        $messageId = $request->input('message');
        $attachmentId = $request->input('attachment');
        if (!$this->validMailboxToken($messageId) || !$this->validMailboxToken($attachmentId)) {
            return (new Response(
                'Attachment not found.',
                404,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            ))->withHeaders($this->attachmentSecurityHeaders());
        }

        $attachment = $this->mailboxClient->attachment($credentials, $folder, $messageId, $attachmentId);

        $contentType = $this->attachmentPolicy->safeContentType($attachment->contentType);
        try {
            $this->attachmentPolicy->validateDownload($attachment->bytes, strlen($attachment->content));
        } catch (RuntimeException) {
            $this->audit->record('mail.attachment_blocked', [
                'mailbox' => $credentials->email,
                'folder' => $request->input('folder', 'INBOX'),
                'reason' => 'size_limit',
                'reported_bytes' => (string) $attachment->bytes,
            ]);

            return (new Response(
                'Attachment exceeds the configured size limit.',
                413,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            ))->withHeaders($this->attachmentSecurityHeaders());
        }

        $downloadMode = $request->input('inline') === '1'
            && $attachment->inline
            && $this->attachmentPolicy->inlineContentTypeAllowed($contentType)
                ? 'inline'
                : 'attachment';
        $contentType = $this->attachmentPolicy->downloadContentType($contentType, $downloadMode);
        $disposition = $this->attachmentPolicy->contentDisposition($downloadMode, $attachment->filename);

        return (new Response($attachment->content, 200, ['Content-Type' => $contentType]))
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Content-Length', (string) strlen($attachment->content))
            ->withHeaders($this->attachmentSecurityHeaders());
    }

    private function applyAction(
        MailboxCredentials $credentials,
        string $folder,
        string $id,
        string $action,
        string $targetFolder,
        ?string $archiveFolder,
    ): void {
        match ($action) {
            'seen' => $this->mailboxClient->markSeen($credentials, $folder, $id, true),
            'unseen' => $this->mailboxClient->markSeen($credentials, $folder, $id, false),
            'flag' => $this->mailboxClient->flag($credentials, $folder, $id, true),
            'unflag' => $this->mailboxClient->flag($credentials, $folder, $id, false),
            'copy' => $this->copy($credentials, $folder, $id, $targetFolder),
            'move' => $this->move($credentials, $folder, $id, $targetFolder),
            'archive' => $this->move($credentials, $folder, $id, $archiveFolder ?? ''),
            'delete' => $this->mailboxClient->delete($credentials, $folder, $id),
            default => null,
        };
    }

    private function auditAction(
        MailboxCredentials $credentials,
        string $folder,
        string $action,
        int $count,
        int $succeeded,
        string $targetFolder,
        ?string $archiveFolder,
    ): void {
        if (!in_array($action, ['seen', 'unseen', 'flag', 'unflag', 'copy', 'move', 'archive', 'delete'], true)) {
            return;
        }

        $effectiveTarget = $action === 'archive' ? $archiveFolder : $targetFolder;
        $this->audit->record('mail.message_action', [
            'mailbox' => $credentials->email,
            'action' => $action,
            'folder' => $folder,
            'target_folder' => $effectiveTarget === '' ? null : $effectiveTarget,
            'count' => (string) $count,
            'succeeded' => (string) $succeeded,
        ]);
    }

    private function actionCanMutate(string $action, string $targetFolder, ?string $archiveFolder): bool
    {
        return match ($action) {
            'copy', 'move' => $targetFolder !== '',
            'archive' => $archiveFolder !== null && $archiveFolder !== '',
            'seen', 'unseen', 'flag', 'unflag', 'delete' => true,
            default => false,
        };
    }

    private function updateCacheAfterAction(
        MailboxCredentials $credentials,
        string $folder,
        string $id,
        string $action,
        string $targetFolder,
        ?string $archiveFolder,
    ): void {
        if ($this->messageMetadataCache === null) {
            return;
        }

        try {
            match ($action) {
                'seen' => $this->messageMetadataCache->updateFlags($credentials->email, $folder, $id, seen: true),
                'unseen' => $this->messageMetadataCache->updateFlags($credentials->email, $folder, $id, seen: false),
                'flag' => $this->messageMetadataCache->updateFlags($credentials->email, $folder, $id, flagged: true),
                'unflag' => $this->messageMetadataCache->updateFlags($credentials->email, $folder, $id, flagged: false),
                'copy' => $this->messageMetadataCache->copy($credentials->email, $folder, $id, $targetFolder),
                'move' => $this->messageMetadataCache->move($credentials->email, $folder, $id, $targetFolder),
                'archive' => $this->messageMetadataCache->move($credentials->email, $folder, $id, $archiveFolder ?? ''),
                'delete' => $this->messageMetadataCache->delete($credentials->email, $folder, $id),
                default => null,
            };
        } catch (Throwable) {
            // Cached fallback metadata must never block live mailbox actions.
        }
    }

    private function mailboxRights(MailboxCredentials $credentials, string $folder): MailboxRights
    {
        try {
            if (!$this->mailboxClient->capabilities($credentials)->acl) {
                return new MailboxRights();
            }

            return $this->mailboxClient->mailboxRights($credentials, $folder);
        } catch (Throwable) {
            return new MailboxRights();
        }
    }

    private function actionAllowedByRights(string $action, MailboxRights $rights): bool
    {
        if (!$rights->available()) {
            return true;
        }

        return match ($action) {
            'seen', 'unseen' => $rights->canKeepSeen(),
            'flag', 'unflag' => $rights->canWriteFlags(),
            'copy' => $rights->canRead(),
            'move', 'archive', 'delete' => $rights->canDeleteMessages(),
            default => true,
        };
    }

    /**
     * @return list<string>
     */
    private function messageIds(Request $request): array
    {
        $singleId = $request->input('id');
        if ($this->validMailboxToken($singleId)) {
            return [$singleId];
        }

        return array_slice(array_values(array_filter(
            $request->inputList('selected_ids'),
            fn (string $id): bool => $this->validMailboxToken($id),
        )), 0, 250);
    }

    private function validMailboxToken(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 512
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function redirectUrl(string $folder, Request $request): string
    {
        $query = ['folder' => $folder];
        if ($request->input('q') !== '') {
            $query['q'] = $request->input('q');
        }

        if ($request->intInput('page', 1) > 1) {
            $query['page'] = (string) $request->intInput('page', 1);
        }

        return '/mailbox?' . http_build_query($query);
    }

    private function move(MailboxCredentials $credentials, string $folder, string $id, string $targetFolder): void
    {
        if ($targetFolder === '' || $targetFolder === $folder) {
            return;
        }

        $this->mailboxClient->move($credentials, $folder, $id, $targetFolder);
    }

    private function copy(MailboxCredentials $credentials, string $folder, string $id, string $targetFolder): void
    {
        if ($targetFolder === '' || $targetFolder === $folder) {
            return;
        }

        $this->mailboxClient->copy($credentials, $folder, $id, $targetFolder);
    }

    private function validTargetFolder(
        MailboxCredentials $credentials,
        string $currentFolder,
        string $targetFolder,
    ): string {
        if ($targetFolder === '' || $targetFolder === $currentFolder) {
            return '';
        }

        try {
            foreach ($this->mailboxClient->folders($credentials) as $folder) {
                if ($folder->selectable && hash_equals($folder->name, $targetFolder)) {
                    return $targetFolder;
                }
            }
        } catch (Throwable) {
        }

        return '';
    }

    private function archiveFolder(MailboxCredentials $credentials): string
    {
        foreach ($this->mailboxClient->folders($credentials) as $folder) {
            if (in_array('archive', $folder->specialUse, true) || strcasecmp($folder->displayName, 'Archive') === 0) {
                return $folder->name;
            }
        }

        $this->mailboxClient->createFolder($credentials, 'Archive');

        return 'Archive';
    }

    private function attachmentContentSecurityPolicy(): string
    {
        return "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; sandbox";
    }

    /**
     * @return array<string, string>
     */
    private function attachmentSecurityHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Download-Options' => 'noopen',
            'Content-Security-Policy' => $this->attachmentContentSecurityPolicy(),
        ];
    }
}

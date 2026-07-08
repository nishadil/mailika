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
use Mailika\Security\CsrfTokenManager;
use Throwable;

final readonly class FolderAclController
{
    private const ALLOWED_RIGHTS = ['l', 'r', 's', 'w', 'i', 'p', 'k', 'x', 't', 'e', 'a', 'c', 'd'];

    public function __construct(
        private CsrfTokenManager $csrf,
        private CredentialVault $vault,
        private MailboxClientInterface $mailboxClient,
        private AuditLogger $audit,
    ) {
    }

    public function save(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        $folder = $request->input('folder', 'INBOX');
        $identifier = $this->normalizeIdentifier($request->input('identifier'));
        $rights = $this->rightsFromRequest($request);

        if (
            !$this->csrf->validate($request->input('_csrf'))
            || !$this->validIdentifier($identifier)
            || !$rights->available()
            || !$this->canAdministerAcl($credentials, $folder)
        ) {
            return Response::redirect($this->redirectUrl($folder));
        }

        try {
            $this->mailboxClient->setMailboxAcl($credentials, $folder, $identifier, $rights);
            $this->audit->record('mail.acl_updated', [
                'mailbox' => $credentials->email,
                'folder' => $folder,
                'acl_identifier' => $identifier,
                'acl_rights' => $rights->raw,
            ]);
        } catch (Throwable) {
            $this->audit->record('mail.acl_update_failed', [
                'mailbox' => $credentials->email,
                'folder' => $folder,
                'acl_identifier' => $identifier,
            ]);
        }

        return Response::redirect($this->redirectUrl($folder));
    }

    public function delete(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        $folder = $request->input('folder', 'INBOX');
        $identifier = $this->normalizeIdentifier($request->input('identifier'));

        if (
            !$this->csrf->validate($request->input('_csrf'))
            || !$this->validIdentifier($identifier)
            || !$this->canAdministerAcl($credentials, $folder)
        ) {
            return Response::redirect($this->redirectUrl($folder));
        }

        try {
            $this->mailboxClient->deleteMailboxAcl($credentials, $folder, $identifier);
            $this->audit->record('mail.acl_deleted', [
                'mailbox' => $credentials->email,
                'folder' => $folder,
                'acl_identifier' => $identifier,
            ]);
        } catch (Throwable) {
            $this->audit->record('mail.acl_delete_failed', [
                'mailbox' => $credentials->email,
                'folder' => $folder,
                'acl_identifier' => $identifier,
            ]);
        }

        return Response::redirect($this->redirectUrl($folder));
    }

    private function canAdministerAcl(MailboxCredentials $credentials, string $folder): bool
    {
        try {
            if (!$this->mailboxClient->capabilities($credentials)->acl) {
                return false;
            }

            $rights = $this->mailboxClient->mailboxRights($credentials, $folder);
            return $rights->available() && $rights->canAdminister();
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', '', $identifier) ?? ''), 0, 255);
    }

    private function validIdentifier(string $identifier): bool
    {
        return $identifier !== '';
    }

    private function rightsFromRequest(Request $request): MailboxRights
    {
        $selected = [];
        foreach ($request->inputList('rights') as $right) {
            $right = strtolower($right);
            if (in_array($right, self::ALLOWED_RIGHTS, true)) {
                $selected[] = $right;
            }
        }

        $ordered = array_values(array_intersect(self::ALLOWED_RIGHTS, array_unique($selected)));

        return new MailboxRights(implode('', $ordered));
    }

    private function redirectUrl(string $folder): string
    {
        return '/mailbox?folder=' . urlencode($folder === '' ? 'INBOX' : $folder);
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\MailboxCredentials;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Mail\MailboxClientInterface;
use Mailika\Security\CsrfTokenManager;
use Throwable;

final readonly class FolderController
{
    public function __construct(
        private CsrfTokenManager $csrf,
        private CredentialVault $vault,
        private MailboxClientInterface $mailboxClient,
        private AuditLogger $audit,
    ) {
    }

    public function create(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        $name = $this->normalizeFolderName($request->input('name'));
        if (
            !$this->csrf->validate($request->input('_csrf'))
            || !$this->validNewFolderName($name)
            || !$this->canCreateFolder($credentials)
        ) {
            return Response::redirect('/mailbox');
        }

        try {
            $this->mailboxClient->createFolder($credentials, $name);
            $this->audit->record('mail.folder_created', [
                'mailbox' => $credentials->email,
                'folder' => $name,
            ]);
            return Response::redirect('/mailbox?folder=' . urlencode($name));
        } catch (Throwable) {
            $this->audit->record('mail.folder_create_failed', [
                'mailbox' => $credentials->email,
                'folder' => $name,
            ]);
        }

        return Response::redirect('/mailbox');
    }

    public function rename(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        $folder = $request->input('folder');
        $newName = $this->normalizeFolderName($request->input('new_name'));

        if (
            $this->csrf->validate($request->input('_csrf'))
            && $this->folderCanBeManaged($credentials, $folder)
            && $this->validNewFolderName($newName)
            && $newName !== $folder
        ) {
            try {
                $this->mailboxClient->renameFolder($credentials, $folder, $newName);
                $this->audit->record('mail.folder_renamed', [
                    'mailbox' => $credentials->email,
                    'folder' => $folder,
                    'target_folder' => $newName,
                ]);
                return Response::redirect('/mailbox?folder=' . urlencode($newName));
            } catch (Throwable) {
                $this->audit->record('mail.folder_rename_failed', [
                    'mailbox' => $credentials->email,
                    'folder' => $folder,
                    'target_folder' => $newName,
                ]);
            }
        }

        return Response::redirect('/mailbox?folder=' . urlencode($folder === '' ? 'INBOX' : $folder));
    }

    public function delete(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        $folder = $request->input('folder');
        if ($this->csrf->validate($request->input('_csrf')) && $this->folderCanBeManaged($credentials, $folder)) {
            try {
                $this->mailboxClient->deleteFolder($credentials, $folder);
                $this->audit->record('mail.folder_deleted', [
                    'mailbox' => $credentials->email,
                    'folder' => $folder,
                ]);
            } catch (Throwable) {
                $this->audit->record('mail.folder_delete_failed', [
                    'mailbox' => $credentials->email,
                    'folder' => $folder,
                ]);
            }
        }

        return Response::redirect('/mailbox');
    }

    private function validNewFolderName(string $folder): bool
    {
        return $folder !== ''
            && strlen($folder) <= 512
            && preg_match('/[\x00-\x1F\x7F]/', $folder) !== 1;
    }

    private function normalizeFolderName(string $folder): string
    {
        return trim($folder, " \t\n\r\0\x0B/");
    }

    private function folderCanBeManaged(MailboxCredentials $credentials, string $folder): bool
    {
        if ($folder === '' || strcasecmp($folder, 'INBOX') === 0) {
            return false;
        }

        try {
            foreach ($this->mailboxClient->folders($credentials) as $mailboxFolder) {
                if ($mailboxFolder->name !== $folder) {
                    continue;
                }

                return $mailboxFolder->selectable
                    && $mailboxFolder->specialUse === []
                    && $this->canManageFolderRights($credentials, $folder);
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private function canCreateFolder(MailboxCredentials $credentials): bool
    {
        try {
            if (!$this->mailboxClient->capabilities($credentials)->acl) {
                return true;
            }

            $rights = $this->mailboxClient->mailboxRights($credentials, 'INBOX');
            return !$rights->available() || $rights->canCreateMailbox();
        } catch (Throwable) {
            return true;
        }
    }

    private function canManageFolderRights(MailboxCredentials $credentials, string $folder): bool
    {
        try {
            if (!$this->mailboxClient->capabilities($credentials)->acl) {
                return true;
            }

            $rights = $this->mailboxClient->mailboxRights($credentials, $folder);
            return !$rights->available() || $rights->canDeleteMailbox();
        } catch (Throwable) {
            return true;
        }
    }
}

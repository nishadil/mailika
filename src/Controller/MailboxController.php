<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Auth\CredentialVault;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Mail\MailboxClientInterface;
use Mailika\Mail\Message;
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

        try {
            $folders = $this->mailboxClient->folders($credentials);
            $messages = $this->mailboxClient->messages($credentials, $folder);
        } catch (Throwable $exception) {
            $warning = $exception->getMessage();
        }

        return new Response($this->view->render('mailbox/index', [
            'title' => 'Mailbox',
            'csrfToken' => $this->csrf->token(),
            'credentials' => $credentials,
            'folder' => $folder,
            'folders' => $folders,
            'messages' => $messages,
            'warning' => $warning,
        ]));
    }

    public function message(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        $folder = $request->input('folder', 'INBOX');
        $id = $request->input('id');

        try {
            $message = $this->mailboxClient->message($credentials, $folder, $id);
        } catch (Throwable $exception) {
            $message = new Message(
                $id,
                '',
                $credentials->email,
                'Message unavailable',
                '',
                '',
                $exception->getMessage(),
            );
        }

        return new Response($this->view->render('mailbox/message', [
            'title' => $message->subject,
            'csrfToken' => $this->csrf->token(),
            'credentials' => $credentials,
            'folder' => $folder,
            'message' => $message,
            'safeHtml' => $this->sanitizer->sanitize($message->htmlBody),
        ]));
    }
}

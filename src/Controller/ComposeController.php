<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Auth\CredentialVault;
use Mailika\Config\Config;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Mail\SmtpSenderInterface;
use Mailika\Security\CsrfTokenManager;
use Mailika\Support\View;
use Mailika\Validation\Validator;
use Throwable;

final readonly class ComposeController
{
    public function __construct(
        private Config $config,
        private View $view,
        private CsrfTokenManager $csrf,
        private CredentialVault $vault,
        private SmtpSenderInterface $sender,
    ) {
    }

    public function show(Request $request): Response
    {
        if ($this->vault->current() === null) {
            return Response::redirect('/login');
        }

        return $this->form();
    }

    public function send(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return $this->form('Your session token expired. Please try again.');
        }

        $to = array_values(array_filter(array_map('trim', explode(',', $request->input('to')))));
        foreach ($to as $recipient) {
            if (!Validator::email($recipient)) {
                return $this->form('One or more recipients are invalid.');
            }
        }

        if ($to === []) {
            return $this->form('Add at least one recipient.');
        }

        $subject = mb_substr($request->input('subject'), 0, 255);
        $body = $request->input('body');

        try {
            $this->sender->send(
                $credentials,
                $to,
                $subject === '' ? '(no subject)' : $subject,
                $body,
                nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                $this->attachments($request),
            );

            return Response::redirect('/mailbox');
        } catch (Throwable) {
            return $this->form('The message could not be sent. Check SMTP settings and try again.');
        }
    }

    private function form(?string $error = null): Response
    {
        return new Response($this->view->render('compose/index', [
            'title' => 'Compose',
            'csrfToken' => $this->csrf->token(),
            'error' => $error,
        ]), $error === null ? 200 : 422);
    }

    /**
     * @return list<array{path:string,name:string,mime:string}>
     */
    private function attachments(Request $request): array
    {
        $file = $request->files['attachment'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size > $this->config->int('mail.max_attachment_bytes')) {
            throw new \RuntimeException('Attachment exceeds configured size limit.');
        }

        return [[
            'path' => (string) $file['tmp_name'],
            'name' => basename((string) $file['name']),
            'mime' => (string) ($file['type'] ?? 'application/octet-stream'),
        ]];
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Draft\DraftRepositoryInterface;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Security\CsrfTokenManager;
use Mailika\Support\View;

final readonly class DraftController
{
    public function __construct(
        private View $view,
        private CsrfTokenManager $csrf,
        private CredentialVault $vault,
        private DraftRepositoryInterface $drafts,
        private AuditLogger $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        return new Response($this->view->render('drafts/index', [
            'title' => 'Drafts',
            'csrfToken' => $this->csrf->token(),
            'drafts' => $this->drafts->listForMailbox($credentials->email),
        ]));
    }

    public function delete(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if ($this->csrf->validate($request->input('_csrf'))) {
            $this->drafts->deleteForMailbox($credentials->email, $request->input('id'));
            $this->audit->record('mail.draft_deleted', [
                'mailbox' => $credentials->email,
            ]);
        }

        return Response::redirect('/drafts');
    }
}

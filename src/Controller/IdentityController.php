<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Identity\Identity;
use Mailika\Identity\IdentityRepositoryInterface;
use Mailika\Security\CsrfTokenManager;
use Mailika\Support\View;
use Mailika\Validation\Validator;

final readonly class IdentityController
{
    public function __construct(
        private View $view,
        private CsrfTokenManager $csrf,
        private CredentialVault $vault,
        private IdentityRepositoryInterface $identities,
        private AuditLogger $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        return new Response($this->view->render('identities/index', [
            'title' => 'Identities',
            'csrfToken' => $this->csrf->token(),
            'identities' => $this->identitiesFor($credentials->email),
            'error' => null,
        ]));
    }

    public function save(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return Response::redirect('/identities');
        }

        $email = Validator::normalizeEmail($request->input('email'));
        if (!Validator::email($email)) {
            return new Response($this->view->render('identities/index', [
                'title' => 'Identities',
                'csrfToken' => $this->csrf->token(),
                'identities' => $this->identitiesFor($credentials->email),
                'error' => 'Enter a valid identity email address.',
            ]), 422);
        }

        $replyTo = Validator::normalizeEmail($request->input('reply_to'));
        if ($replyTo !== '' && !Validator::email($replyTo)) {
            return new Response($this->view->render('identities/index', [
                'title' => 'Identities',
                'csrfToken' => $this->csrf->token(),
                'identities' => $this->identitiesFor($credentials->email),
                'error' => 'Enter a valid Reply-To email address.',
            ]), 422);
        }

        $this->identities->save(
            $credentials->email,
            new Identity(
                mb_substr($request->input('display_name'), 0, 255),
                $email,
                $replyTo === '' ? null : $replyTo,
                $request->input('default') === '1',
            ),
        );
        $this->audit->record('identity.saved', [
            'mailbox' => $credentials->email,
            'identity' => $email,
            'default' => $request->input('default') === '1' ? 'true' : 'false',
            'has_reply_to' => $replyTo === '' ? 'false' : 'true',
        ]);

        return Response::redirect('/identities');
    }

    public function delete(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return Response::redirect('/identities');
        }

        $id = $request->intInput('id');
        if ($id > 0) {
            $identity = $this->identityById($credentials->email, $id);
            $this->identities->deleteForMailbox($credentials->email, $id);
            $this->audit->record('identity.deleted', [
                'mailbox' => $credentials->email,
                'identity' => $identity?->email,
            ]);
        }

        return Response::redirect('/identities');
    }

    /**
     * @return list<Identity>
     */
    private function identitiesFor(string $fallbackEmail): array
    {
        $identities = $this->identities->listForMailbox($fallbackEmail);

        return $identities === [] ? [new Identity('', $fallbackEmail, null, true)] : $identities;
    }

    private function identityById(string $mailboxIdentity, int $id): ?Identity
    {
        foreach ($this->identities->listForMailbox($mailboxIdentity) as $identity) {
            if ($identity->id === $id) {
                return $identity;
            }
        }

        return null;
    }
}

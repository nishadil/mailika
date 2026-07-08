<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\LoginPrefillStore;
use Mailika\Config\Config;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Mail\MailAccountProfile;
use Mailika\Mail\MailAccountProfileRepositoryInterface;
use Mailika\Security\CsrfTokenManager;
use Mailika\Security\SessionManager;
use Mailika\Support\View;
use Mailika\Validation\Validator;

final readonly class AccountController
{
    public function __construct(
        private Config $config,
        private View $view,
        private CsrfTokenManager $csrf,
        private CredentialVault $vault,
        private MailAccountProfileRepositoryInterface $profiles,
        private AuditLogger $audit,
        private LoginPrefillStore $loginPrefill,
    ) {
    }

    public function index(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        return $this->render($credentials->email);
    }

    public function save(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return Response::redirect('/accounts');
        }

        $profile = $this->profileFromRequest($request);
        if (!$profile instanceof MailAccountProfile) {
            return $this->render($credentials->email, 'Enter a valid account profile.', 422);
        }

        $this->profiles->save($credentials->email, $profile);
        $this->audit->record('account_profile.saved', [
            'mailbox' => $credentials->email,
            'profile' => $profile->email,
            'imap_host' => $profile->imapHost,
            'smtp_host' => $profile->smtpHost,
        ]);

        return Response::redirect('/accounts');
    }

    public function delete(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return Response::redirect('/accounts');
        }

        $id = $request->intInput('id');
        if ($id > 0) {
            $profile = $this->profileById($credentials->email, $id);
            $this->profiles->deleteForMailbox($credentials->email, $id);
            $this->audit->record('account_profile.deleted', [
                'mailbox' => $credentials->email,
                'profile' => $profile?->email,
            ]);
        }

        return Response::redirect('/accounts');
    }

    public function select(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return Response::redirect('/accounts');
        }

        $profile = $this->profileById($credentials->email, $request->intInput('id'));
        if ($profile === null) {
            return Response::redirect('/accounts');
        }

        $this->loginPrefill->store($profile);
        $this->vault->clear();
        SessionManager::regenerate();
        $this->csrf->rotate();
        $this->audit->record('account_profile.selected', [
            'mailbox' => $credentials->email,
            'profile' => $profile->email,
            'imap_host' => $profile->imapHost,
            'smtp_host' => $profile->smtpHost,
        ]);

        return Response::redirect('/login');
    }

    private function render(string $mailboxIdentity, ?string $error = null, int $status = 200): Response
    {
        $current = $this->vault->current();

        return new Response($this->view->render('accounts/index', [
            'title' => 'Account profiles',
            'csrfToken' => $this->csrf->token(),
            'error' => $error,
            'profiles' => $this->profiles->listForMailbox($mailboxIdentity),
            'currentProfile' => $current === null ? null : new MailAccountProfile(
                $current->email,
                $current->email,
                $current->imapHost,
                $current->imapPort,
                $current->imapTls,
                $current->smtpHost,
                $current->smtpPort,
                $current->smtpTls,
            ),
        ]), $status);
    }

    private function profileFromRequest(Request $request): ?MailAccountProfile
    {
        $email = Validator::normalizeEmail($request->input('email'));
        $label = $this->label($request->input('label'), $email);
        $imapHost = strtolower($request->input('imap_host'));
        $smtpHost = strtolower($request->input('smtp_host'));
        $imapPort = $request->intInput('imap_port', 993);
        $smtpPort = $request->intInput('smtp_port', 587);
        $smtpTls = $this->smtpSecurityMode($request->input('smtp_tls', 'starttls'));

        if (!Validator::email($email) || $label === null || $smtpTls === null) {
            return null;
        }

        if (!Validator::tcpPort($imapPort) || !Validator::tcpPort($smtpPort)) {
            return null;
        }

        if (!Validator::hostAllowed($imapHost, $this->config->stringList('mail.allowed_imap_hosts'))) {
            return null;
        }

        if (!Validator::hostAllowed($smtpHost, $this->config->stringList('mail.allowed_smtp_hosts'))) {
            return null;
        }

        if ($this->config->bool('mail.require_tls', true) && $request->input('imap_tls', '1') !== '1') {
            return null;
        }

        if ($this->config->bool('mail.require_tls', true) && $smtpTls === 'none') {
            return null;
        }

        return new MailAccountProfile(
            $label,
            $email,
            $imapHost,
            $imapPort,
            $request->input('imap_tls', '1') === '1',
            $smtpHost,
            $smtpPort,
            $smtpTls,
            $request->intInput('id') > 0 ? $request->intInput('id') : null,
        );
    }

    private function label(string $label, string $fallbackEmail): ?string
    {
        $label = trim($label) !== '' ? trim($label) : $fallbackEmail;
        if ($label === '' || mb_strlen($label) > 128 || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            return null;
        }

        return $label;
    }

    private function smtpSecurityMode(string $mode): ?string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, ['starttls', 'smtps', 'none'], true) ? $mode : null;
    }

    private function profileById(string $mailboxIdentity, int $id): ?MailAccountProfile
    {
        foreach ($this->profiles->listForMailbox($mailboxIdentity) as $profile) {
            if ($profile->id === $id) {
                return $profile;
            }
        }

        return null;
    }
}

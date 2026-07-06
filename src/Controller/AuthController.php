<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Mail\MailboxClientInterface;
use Mailika\Security\CsrfTokenManager;
use Mailika\Security\RateLimiter;
use Mailika\Security\SessionManager;
use Mailika\Support\View;
use Mailika\Validation\Validator;
use Throwable;

final readonly class AuthController
{
    public function __construct(
        private Config $config,
        private View $view,
        private CsrfTokenManager $csrf,
        private CredentialVault $vault,
        private MailboxClientInterface $mailboxClient,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        if ($this->vault->isAuthenticated()) {
            return Response::redirect('/mailbox');
        }

        return new Response($this->view->render('auth/login', [
            'title' => 'Sign in',
            'csrfToken' => $this->csrf->token(),
            'error' => null,
            'defaults' => $this->defaults(),
        ]));
    }

    public function login(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('_csrf'))) {
            return $this->loginError('Your session token expired. Please try again.');
        }

        $rateKey = 'login|' . $request->ip() . '|' . strtolower($request->input('email'));
        $loginAllowed = $this->rateLimiter->allow(
            $rateKey,
            $this->config->int('rate_limit.login_attempts', 8),
            $this->config->int('rate_limit.login_window_seconds', 300),
        );

        if (!$loginAllowed) {
            $this->audit->record('login.rate_limited', ['ip' => $request->ip()]);
            return $this->loginError('Too many sign-in attempts. Wait a few minutes and try again.');
        }

        $credentials = new MailboxCredentials(
            strtolower($request->input('email')),
            $request->input('password'),
            strtolower($request->input('imap_host')),
            $request->intInput('imap_port', 993),
            $request->input('imap_tls', '1') === '1',
            strtolower($request->input('smtp_host')),
            $request->intInput('smtp_port', 587),
            $request->input('smtp_tls', 'starttls'),
        );

        if (!Validator::email($credentials->email)) {
            return $this->loginError('Enter a valid mailbox email address.');
        }

        if (!Validator::hostAllowed($credentials->imapHost, $this->config->stringList('mail.allowed_imap_hosts'))) {
            return $this->loginError('This IMAP host is not allowed by the Mailika administrator.');
        }

        if ($this->config->bool('mail.require_tls', true) && !$credentials->imapTls) {
            return $this->loginError('TLS is required for IMAP in this deployment.');
        }

        try {
            if ($this->config->bool('mail.imap_validate_login', true)) {
                $this->mailboxClient->authenticate($credentials);
            }

            SessionManager::regenerate();
            $this->csrf->rotate();
            $this->vault->store($credentials);
            $this->audit->record('login.success', [
                'mailbox' => $credentials->email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return Response::redirect('/mailbox');
        } catch (Throwable) {
            $this->audit->record('login.failed', [
                'mailbox' => $credentials->email,
                'ip' => $request->ip(),
            ]);

            return $this->loginError('Mailbox sign-in failed. Check the server, TLS, username, and password.');
        }
    }

    public function logout(Request $request): Response
    {
        if ($this->csrf->validate($request->input('_csrf'))) {
            $this->vault->clear();
            SessionManager::destroy();
        }

        return Response::redirect('/login');
    }

    private function loginError(string $message): Response
    {
        return new Response($this->view->render('auth/login', [
            'title' => 'Sign in',
            'csrfToken' => $this->csrf->token(),
            'error' => $message,
            'defaults' => $this->defaults(),
        ]), 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'imap_host' => $this->config->string('mail.default_imap_host'),
            'imap_port' => $this->config->int('mail.default_imap_port', 993),
            'imap_tls' => $this->config->bool('mail.default_imap_tls', true),
            'smtp_host' => $this->config->string('mail.default_smtp_host'),
            'smtp_port' => $this->config->int('mail.default_smtp_port', 587),
            'smtp_tls' => $this->config->string('mail.default_smtp_tls', 'starttls'),
        ];
    }
}

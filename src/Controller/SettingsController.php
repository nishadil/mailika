<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Auth\CredentialVault;
use Mailika\Config\Config;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Security\CsrfTokenManager;
use Mailika\Support\View;

final readonly class SettingsController
{
    public function __construct(
        private Config $config,
        private View $view,
        private CsrfTokenManager $csrf,
        private CredentialVault $vault,
    ) {
    }

    public function show(Request $request): Response
    {
        if ($this->vault->current() === null) {
            return Response::redirect('/login');
        }

        return new Response($this->view->render('settings/index', [
            'title' => 'Settings',
            'csrfToken' => $this->csrf->token(),
            'saved' => false,
            'remoteImages' => (bool) ($_SESSION['settings.remote_images'] ?? $this->config->bool('mail.remote_images')),
        ]));
    }

    public function save(Request $request): Response
    {
        if ($this->vault->current() === null) {
            return Response::redirect('/login');
        }

        if ($this->csrf->validate($request->input('_csrf'))) {
            $_SESSION['settings.remote_images'] = $request->input('remote_images') === '1';
        }

        return new Response($this->view->render('settings/index', [
            'title' => 'Settings',
            'csrfToken' => $this->csrf->token(),
            'saved' => true,
            'remoteImages' => (bool) ($_SESSION['settings.remote_images'] ?? false),
        ]));
    }
}

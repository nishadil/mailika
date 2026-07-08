<?php

declare(strict_types=1);

namespace Mailika\Controller;

use DateTimeZone;
use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Preferences\LocaleCatalog;
use Mailika\Preferences\Preferences;
use Mailika\Preferences\PreferencesRepositoryInterface;
use Mailika\Security\CsrfTokenManager;
use Mailika\Support\View;

final readonly class SettingsController
{
    public function __construct(
        private View $view,
        private CsrfTokenManager $csrf,
        private CredentialVault $vault,
        private PreferencesRepositoryInterface $preferences,
        private AuditLogger $audit,
    ) {
    }

    public function show(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        return $this->render($this->preferences->get($credentials->email));
    }

    public function save(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if ($this->csrf->validate($request->input('_csrf'))) {
            $preferences = new Preferences(
                $this->locale($request->input('locale')),
                $this->timezone($request->input('timezone')),
                $request->input('remote_images') === '1',
                $this->theme($request->input('theme')),
                $request->input('threaded_listing') === '1',
                $this->messagesPerPage($request->intInput('messages_per_page', 50)),
            );
            $this->preferences->save($credentials->email, $preferences);
            $this->audit->record('settings.updated', [
                'mailbox' => $credentials->email,
                'locale' => $preferences->locale,
                'timezone' => $preferences->timezone,
                'remote_images' => $preferences->remoteImages ? 'true' : 'false',
                'theme' => $preferences->theme,
                'threaded_listing' => $preferences->threadedListing ? 'true' : 'false',
                'messages_per_page' => (string) $preferences->messagesPerPage,
            ]);

            return $this->render($preferences, true);
        }

        return $this->render($this->preferences->get($credentials->email));
    }

    private function render(Preferences $preferences, bool $saved = false): Response
    {
        return new Response($this->view->render('settings/index', [
            'title' => 'Settings',
            'csrfToken' => $this->csrf->token(),
            'saved' => $saved,
            'preferences' => $preferences,
            'locales' => LocaleCatalog::labels(),
            'timezones' => $this->timezones(),
        ]));
    }

    private function locale(string $value): string
    {
        return LocaleCatalog::normalize($value);
    }

    private function timezone(string $value): string
    {
        return in_array($value, DateTimeZone::listIdentifiers(), true) ? $value : 'UTC';
    }

    private function theme(string $value): string
    {
        return in_array($value, ['system', 'light', 'dark'], true) ? $value : 'system';
    }

    private function messagesPerPage(int $value): int
    {
        return in_array($value, [25, 50, 100], true) ? $value : 50;
    }

    /**
     * @return list<string>
     */
    private function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }
}

<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Contact\Contact;
use Mailika\Contact\ContactDirectoryInterface;
use Mailika\Contact\ContactGroup;
use Mailika\Contact\ContactGroupRepositoryInterface;
use Mailika\Contact\ContactRepositoryInterface;
use Mailika\Contact\VcardParser;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Security\CsrfTokenManager;
use Mailika\Support\View;
use Mailika\Validation\Validator;

final readonly class ContactController
{
    public function __construct(
        private View $view,
        private CsrfTokenManager $csrf,
        private CredentialVault $vault,
        private ContactRepositoryInterface $contacts,
        private ContactGroupRepositoryInterface $groups,
        private VcardParser $vcardParser,
        private ContactDirectoryInterface $directory,
        private AuditLogger $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        $directoryQuery = mb_substr($request->input('directory_q'), 0, 128);
        $directoryResults = [];
        if ($directoryQuery !== '' && $this->directory->configured() && mb_strlen($directoryQuery) >= 2) {
            $directoryResults = $this->directory->search($directoryQuery);
            $this->audit->record('contact_directory.searched', [
                'mailbox' => $credentials->email,
                'directory' => $this->directory->name(),
                'query_length' => (string) mb_strlen($directoryQuery),
                'count' => (string) count($directoryResults),
            ]);
        }

        return $this->render($credentials->email, null, $directoryQuery, $directoryResults);
    }

    public function save(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return Response::redirect('/contacts');
        }

        $email = Validator::normalizeEmail($request->input('email'));
        if (!Validator::email($email)) {
            return $this->render($credentials->email, 'Enter a valid contact email address.', '', [], 422);
        }

        $this->contacts->save(
            $credentials->email,
            new Contact(
                mb_substr($request->input('display_name'), 0, 255),
                $email,
                mb_substr($request->input('notes'), 0, 2000),
            ),
        );

        $groupId = $request->intInput('group_id');
        if ($groupId > 0) {
            $this->groups->assignContactByEmail($credentials->email, $email, $groupId);
        }
        $this->audit->record('contact.saved', [
            'mailbox' => $credentials->email,
            'assigned_group' => $groupId > 0 ? 'true' : 'false',
        ]);

        return Response::redirect('/contacts');
    }

    public function createGroup(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return Response::redirect('/contacts');
        }

        $name = mb_substr($request->input('name'), 0, 255);
        if ($name !== '') {
            $this->groups->save($credentials->email, new ContactGroup($name));
            $this->audit->record('contact_group.saved', [
                'mailbox' => $credentials->email,
            ]);
        }

        return Response::redirect('/contacts');
    }

    public function deleteGroup(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return Response::redirect('/contacts');
        }

        $groupId = $request->intInput('group_id');
        if ($groupId > 0) {
            $this->groups->deleteForMailbox($credentials->email, $groupId);
            $this->audit->record('contact_group.deleted', [
                'mailbox' => $credentials->email,
            ]);
        }

        return Response::redirect('/contacts');
    }

    public function delete(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return Response::redirect('/contacts');
        }

        $email = Validator::normalizeEmail($request->input('email'));
        if (Validator::email($email)) {
            $this->contacts->deleteForMailbox($credentials->email, $email);
            $this->audit->record('contact.deleted', [
                'mailbox' => $credentials->email,
            ]);
        }

        return Response::redirect('/contacts');
    }

    public function import(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return Response::redirect('/contacts');
        }

        $content = $this->importContent($request);
        if ($content === '') {
            return $this->renderError($credentials->email, 'Choose a vCard file or paste vCard text.');
        }

        $contacts = $this->vcardParser->parse($content);
        if ($contacts === []) {
            return $this->renderError($credentials->email, 'No valid contacts were found in that vCard data.');
        }

        $groupId = $request->intInput('group_id');
        $groupIdsByName = $this->groupIdsByName($credentials->email);
        foreach ($contacts as $contact) {
            $this->contacts->save($credentials->email, $contact);
            if ($groupId > 0) {
                $this->groups->assignContactByEmail($credentials->email, $contact->email, $groupId);
            }

            foreach ($contact->groups as $groupName) {
                $importedGroupId = $this->ensureGroup($credentials->email, $groupName, $groupIdsByName);
                if ($importedGroupId > 0) {
                    $this->groups->assignContactByEmail($credentials->email, $contact->email, $importedGroupId);
                }
            }
        }
        $this->audit->record('contacts.imported', [
            'mailbox' => $credentials->email,
            'count' => (string) count($contacts),
            'assigned_group' => $groupId > 0 ? 'true' : 'false',
        ]);

        return Response::redirect('/contacts');
    }

    public function export(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        $lines = [];
        foreach ($this->contacts->listForMailbox($credentials->email) as $contact) {
            $card = [
                'BEGIN:VCARD',
                'VERSION:4.0',
                'FN:' . $this->vcardText($contact->displayName),
                'EMAIL:' . $this->vcardText($contact->email),
            ];

            if ($contact->notes !== null && $contact->notes !== '') {
                $card[] = 'NOTE:' . $this->vcardText($contact->notes);
            }

            if ($contact->groups !== []) {
                $card[] = 'CATEGORIES:' . implode(',', array_map($this->vcardText(...), $contact->groups));
            }

            $card[] = 'END:VCARD';
            $lines[] = implode("\r\n", $card);
        }

        return (new Response(implode("\r\n", $lines), 200, ['Content-Type' => 'text/vcard; charset=UTF-8']))
            ->withHeader('Content-Disposition', 'attachment; filename="mailika-contacts.vcf"');
    }

    private function vcardText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace("\n", '\\n', $value);
        $value = str_replace(';', '\\;', $value);

        return str_replace(',', '\\,', $value);
    }

    private function importContent(Request $request): string
    {
        $text = $request->input('vcard_text');
        if ($text !== '') {
            return mb_substr($text, 0, 1_048_576);
        }

        $file = $request->files['vcard'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return '';
        }

        $size = (int) ($file['size'] ?? 0);
        $path = (string) ($file['tmp_name'] ?? '');
        if ($path === '' || $size <= 0 || $size > 1_048_576 || !is_uploaded_file($path)) {
            return '';
        }

        $content = file_get_contents($path);
        return is_string($content) ? $content : '';
    }

    private function renderError(string $mailboxIdentity, string $error): Response
    {
        return $this->render($mailboxIdentity, $error, '', [], 422);
    }

    /**
     * @param list<Contact> $directoryResults
     */
    private function render(
        string $mailboxIdentity,
        ?string $error = null,
        string $directoryQuery = '',
        array $directoryResults = [],
        int $status = 200,
    ): Response {
        return new Response($this->view->render('contacts/index', [
            'title' => 'Contacts',
            'csrfToken' => $this->csrf->token(),
            'contacts' => $this->contacts->listForMailbox($mailboxIdentity),
            'groups' => $this->groups->listForMailbox($mailboxIdentity),
            'error' => $error,
            'directoryConfigured' => $this->directory->configured(),
            'directoryName' => $this->directory->name(),
            'directoryQuery' => $directoryQuery,
            'directoryResults' => $directoryResults,
        ]), $status);
    }

    /**
     * @return array<string, int>
     */
    private function groupIdsByName(string $mailboxIdentity): array
    {
        $groups = [];
        foreach ($this->groups->listForMailbox($mailboxIdentity) as $group) {
            if ($group->id !== null) {
                $groups[mb_strtolower($group->name)] = $group->id;
            }
        }

        return $groups;
    }

    /**
     * @param array<string, int> $groupIdsByName
     */
    private function ensureGroup(string $mailboxIdentity, string $name, array &$groupIdsByName): int
    {
        $name = mb_substr(trim($name), 0, 255);
        if ($name === '') {
            return 0;
        }

        $key = mb_strtolower($name);
        if (isset($groupIdsByName[$key])) {
            return $groupIdsByName[$key];
        }

        $this->groups->save($mailboxIdentity, new ContactGroup($name));
        $groupIdsByName = $this->groupIdsByName($mailboxIdentity);

        return $groupIdsByName[$key] ?? 0;
    }
}

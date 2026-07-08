<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\MailboxCredentials;
use Mailika\Contact\ContactRepositoryInterface;
use Mailika\Crypto\OpenPgpLeakException;
use Mailika\Crypto\OpenPgpLeakGuard;
use Mailika\Draft\DraftMessage;
use Mailika\Draft\DraftRepositoryInterface;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Identity\Identity;
use Mailika\Identity\IdentityRepositoryInterface;
use Mailika\Mail\MailboxClientInterface;
use Mailika\Mail\Message;
use Mailika\Mail\SendEnvelope;
use Mailika\Mail\SmtpDeliveryException;
use Mailika\Mail\SmtpSenderInterface;
use Mailika\Security\AttachmentPolicy;
use Mailika\Security\CsrfTokenManager;
use Mailika\Support\View;
use Mailika\Validation\Validator;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Throwable;

final readonly class ComposeController
{
    public function __construct(
        private View $view,
        private CsrfTokenManager $csrf,
        private CredentialVault $vault,
        private SmtpSenderInterface $sender,
        private MailboxClientInterface $mailboxClient,
        private AttachmentPolicy $attachmentPolicy,
        private ContactRepositoryInterface $contacts,
        private IdentityRepositoryInterface $identities,
        private DraftRepositoryInterface $drafts,
        private OpenPgpLeakGuard $openPgpLeakGuard,
        private AuditLogger $audit,
    ) {
    }

    public function show(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        return $this->form(defaults: $this->prefill($request, $credentials));
    }

    public function send(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return $this->form('Your session token expired. Please try again.', $this->defaultsFromRequest($request));
        }

        if ($request->input('intent') === 'draft') {
            if ($this->containsOpenPgpPrivateKeyMaterial($credentials, $request->input('body'), [], 'draft')) {
                return $this->form(
                    'OpenPGP private key material cannot be sent or saved.',
                    $this->defaultsFromRequest($request),
                );
            }

            $this->saveDraft($credentials, $request);
            $this->auditDraftSaved($credentials, $request);
            return Response::redirect('/drafts');
        }

        $to = Validator::emailList($request->input('to'));
        $cc = Validator::emailList($request->input('cc'));
        $bcc = Validator::emailList($request->input('bcc'));

        foreach ([...$to, ...$cc, ...$bcc] as $recipient) {
            if (!Validator::email($recipient)) {
                return $this->form('One or more recipients are invalid.', $this->defaultsFromRequest($request));
            }
        }

        if ($to === []) {
            return $this->form('Add at least one recipient.', $this->defaultsFromRequest($request));
        }

        $subject = mb_substr($request->input('subject'), 0, 255);
        if (!Validator::headerText($subject)) {
            return $this->form(
                'Message subject contains invalid header characters.',
                $this->defaultsFromRequest($request),
            );
        }

        $body = $request->input('body');

        try {
            $identity = $this->selectedIdentity($credentials, $request);
        } catch (RuntimeException $exception) {
            return $this->form($exception->getMessage(), $this->defaultsFromRequest($request));
        }

        try {
            $attachments = $this->attachments($request);
        } catch (RuntimeException $exception) {
            return $this->form($exception->getMessage(), $this->defaultsFromRequest($request));
        }

        if ($this->containsOpenPgpPrivateKeyMaterial($credentials, $body, $attachments, 'send')) {
            return $this->form(
                'OpenPGP private key material cannot be sent or saved.',
                $this->defaultsFromRequest($request),
            );
        }

        $envelope = new SendEnvelope(
            $to,
            $cc,
            $bcc,
            $subject === '' ? '(no subject)' : $subject,
            $body,
            nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            $attachments,
            $identity->email,
            $this->sourceMessageId($request->input('reply_to_message_id')),
            $identity->replyTo,
        );

        try {
            $this->sender->sendEnvelope($credentials, $envelope);
            $this->auditSent($credentials, $envelope, $request->input('draft_id') !== '');
            $draftId = $request->input('draft_id');
            if ($draftId !== '') {
                $this->drafts->deleteForMailbox($credentials->email, $draftId);
            }

            return Response::redirect('/mailbox');
        } catch (SmtpDeliveryException $exception) {
            $this->auditSendFailed($credentials, $envelope, 'smtp');
            return $this->form($exception->getMessage(), $this->defaultsFromRequest($request));
        } catch (Throwable) {
            $this->auditSendFailed($credentials, $envelope, 'unexpected');
            return $this->form(
                'The message could not be sent. Check SMTP settings and try again.',
                $this->defaultsFromRequest($request),
            );
        }
    }

    /**
     * @param array{
     *     to:string,
     *     cc:string,
     *     bcc:string,
     *     subject:string,
     *     body:string,
     *     identity:string,
     *     reply_to_message_id:string,
     *     draft_id:string
     * } $defaults
     */
    private function form(?string $error = null, array $defaults = [
        'to' => '',
        'cc' => '',
        'bcc' => '',
        'subject' => '',
        'body' => '',
        'identity' => '',
        'reply_to_message_id' => '',
        'draft_id' => '',
    ]): Response
    {
        $credentials = $this->vault->current();
        $mailboxIdentity = $credentials === null ? '' : $credentials->email;

        return new Response($this->view->render('compose/index', [
            'title' => 'Compose',
            'csrfToken' => $this->csrf->token(),
            'error' => $error,
            'defaults' => $defaults,
            'identities' => $this->identityOptions($mailboxIdentity),
            'contacts' => $mailboxIdentity === '' ? [] : $this->contacts->listForMailbox($mailboxIdentity),
        ]), $error === null ? 200 : 422);
    }

    /**
     * @return list<array{path:string,name:string,mime:string}>
     */
    private function attachments(Request $request): array
    {
        $uploads = $request->uploadedFiles('attachments');
        if ($uploads === []) {
            $uploads = $request->uploadedFiles('attachment');
        }

        return $this->attachmentPolicy->validateUploads($uploads);
    }

    private function saveDraft(MailboxCredentials $credentials, Request $request): void
    {
        $this->drafts->save(
            $credentials->email,
            new DraftMessage(
                $request->input('draft_id'),
                $request->input('to'),
                $request->input('cc'),
                $request->input('bcc'),
                mb_substr($request->input('subject'), 0, 255),
                $request->input('body'),
                '',
                $this->draftIdentityEmail($credentials->email, $request->input('identity')),
            ),
        );
    }

    private function auditDraftSaved(MailboxCredentials $credentials, Request $request): void
    {
        $this->audit->record('mail.draft_saved', [
            'mailbox' => $credentials->email,
            'has_existing_draft' => $request->input('draft_id') === '' ? 'false' : 'true',
            'has_reply_source' => $request->input('reply_to_message_id') === '' ? 'false' : 'true',
        ]);
    }

    private function auditSent(MailboxCredentials $credentials, SendEnvelope $envelope, bool $fromDraft): void
    {
        $this->audit->record('mail.sent', [
            'mailbox' => $credentials->email,
            'identity' => $envelope->identityEmail,
            'to_count' => (string) count($envelope->to),
            'cc_count' => (string) count($envelope->cc),
            'bcc_count' => (string) count($envelope->bcc),
            'attachment_count' => (string) count($envelope->attachments),
            'from_draft' => $fromDraft ? 'true' : 'false',
            'reply' => $envelope->replyToMessageId === null ? 'false' : 'true',
            'smtp_utf8' => $envelope->requiresSmtpUtf8 ? 'true' : 'false',
        ]);
    }

    private function auditSendFailed(MailboxCredentials $credentials, SendEnvelope $envelope, string $reason): void
    {
        $this->audit->record('mail.send_failed', [
            'mailbox' => $credentials->email,
            'identity' => $envelope->identityEmail,
            'to_count' => (string) count($envelope->to),
            'cc_count' => (string) count($envelope->cc),
            'bcc_count' => (string) count($envelope->bcc),
            'attachment_count' => (string) count($envelope->attachments),
            'reason' => $reason,
            'smtp_utf8' => $envelope->requiresSmtpUtf8 ? 'true' : 'false',
        ]);
    }

    /**
     * @param list<array{path:string,name:string,mime:string}> $attachments
     */
    private function containsOpenPgpPrivateKeyMaterial(
        MailboxCredentials $credentials,
        string $body,
        array $attachments,
        string $intent,
    ): bool {
        try {
            $this->openPgpLeakGuard->assertNoPrivateKeyMaterial($body, $attachments);
            return false;
        } catch (OpenPgpLeakException) {
            $this->audit->record('mail.private_key_blocked', [
                'mailbox' => $credentials->email,
                'intent' => $intent,
                'attachment_count' => (string) count($attachments),
            ]);

            return true;
        }
    }

    /**
     * @return list<Identity>
     */
    private function identityOptions(string $mailboxIdentity): array
    {
        if ($mailboxIdentity === '') {
            return [];
        }

        $identities = [];
        $emails = [];

        foreach ($this->identities->listForMailbox($mailboxIdentity) as $identity) {
            if (Validator::email($identity->email) && !in_array($identity->email, $emails, true)) {
                $identities[] = $identity;
                $emails[] = $identity->email;
            }
        }

        if ($identities === []) {
            return [new Identity('', $mailboxIdentity, null, true)];
        }

        $identityEmails = array_map(static fn (Identity $identity): string => $identity->email, $identities);
        if (!in_array($mailboxIdentity, $identityEmails, true)) {
            $identities[] = new Identity('', $mailboxIdentity);
        }

        return $identities;
    }

    private function selectedIdentity(MailboxCredentials $credentials, Request $request): Identity
    {
        $selected = $request->input('identity');
        $options = $this->identityOptions($credentials->email);

        if ($selected === '' && $options !== []) {
            return $options[0];
        }

        foreach ($options as $identity) {
            if (hash_equals($identity->email, $selected)) {
                return $identity;
            }
        }

        throw new RuntimeException('Selected sender identity is not configured.');
    }

    private function draftIdentityEmail(string $mailboxIdentity, string $selected): ?string
    {
        if ($selected === '') {
            return null;
        }

        foreach ($this->identityOptions($mailboxIdentity) as $identity) {
            if (hash_equals($identity->email, $selected)) {
                return $identity->email;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     to:string,
     *     cc:string,
     *     bcc:string,
     *     subject:string,
     *     body:string,
     *     identity:string,
     *     reply_to_message_id:string,
     *     draft_id:string
     * }
     */
    private function prefill(Request $request, MailboxCredentials $credentials): array
    {
        $defaults = $this->emptyDefaults();

        $draftId = $request->input('draft');
        if ($draftId !== '') {
            $draft = $this->drafts->findForMailbox($credentials->email, $draftId);
            if ($draft !== null) {
                return [
                    'to' => $draft->to,
                    'cc' => $draft->cc,
                    'bcc' => $draft->bcc,
                    'subject' => $draft->subject,
                    'body' => $draft->body,
                    'identity' => $this->draftIdentityEmail($credentials->email, $draft->identityEmail ?? ''),
                    'reply_to_message_id' => '',
                    'draft_id' => $draft->id,
                ];
            }
        }

        $mode = $request->input('mode');
        if (!in_array($mode, ['reply', 'reply-all', 'forward'], true) || $request->input('id') === '') {
            return $defaults;
        }

        try {
            $message = $this->mailboxClient->message(
                $credentials,
                $request->input('folder', 'INBOX'),
                $request->input('id'),
            );
        } catch (Throwable) {
            return $defaults;
        }

        if ($mode === 'forward') {
            return [
                ...$defaults,
                'subject' => $this->prefixedSubject('Fwd:', $message->subject),
                'body' => $this->forwardBody($message),
            ];
        }

        $to = $this->addressesFromHeader($message->replyTo !== '' ? $message->replyTo : $message->from);
        $cc = $mode === 'reply-all' ? $this->replyAllCc($message, $credentials->email, $to) : [];

        return [
            ...$defaults,
            'to' => implode(', ', $to),
            'cc' => implode(', ', $cc),
            'subject' => $this->prefixedSubject('Re:', $message->subject),
            'body' => $this->replyBody($message),
            'reply_to_message_id' => $message->messageId ?? '',
        ];
    }

    /**
     * @return array{
     *     to:string,
     *     cc:string,
     *     bcc:string,
     *     subject:string,
     *     body:string,
     *     identity:string,
     *     reply_to_message_id:string,
     *     draft_id:string
     * }
     */
    private function defaultsFromRequest(Request $request): array
    {
        return [
            'to' => $request->input('to'),
            'cc' => $request->input('cc'),
            'bcc' => $request->input('bcc'),
            'subject' => mb_substr($request->input('subject'), 0, 255),
            'body' => $request->input('body'),
            'identity' => $request->input('identity'),
            'reply_to_message_id' => $request->input('reply_to_message_id'),
            'draft_id' => $request->input('draft_id'),
        ];
    }

    /**
     * @return array{
     *     to:string,
     *     cc:string,
     *     bcc:string,
     *     subject:string,
     *     body:string,
     *     identity:string,
     *     reply_to_message_id:string,
     *     draft_id:string
     * }
     */
    private function emptyDefaults(): array
    {
        return [
            'to' => '',
            'cc' => '',
            'bcc' => '',
            'subject' => '',
            'body' => '',
            'identity' => '',
            'reply_to_message_id' => '',
            'draft_id' => '',
        ];
    }

    /**
     * @return list<string>
     */
    private function addressesFromHeader(string $header): array
    {
        $addresses = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            try {
                $address = Address::create($part)->getAddress();
            } catch (Throwable) {
                $address = $part;
            }

            if (Validator::email($address)) {
                $addresses[] = strtolower($address);
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @param list<string> $primaryRecipients
     * @return list<string>
     */
    private function replyAllCc(Message $message, string $mailboxIdentity, array $primaryRecipients): array
    {
        $excluded = array_map('strtolower', [$mailboxIdentity, ...$primaryRecipients]);
        $candidates = [
            ...$this->addressesFromHeader($message->to),
            ...$this->addressesFromHeader($message->cc),
        ];

        return array_values(array_filter(
            array_unique($candidates),
            static fn (string $address): bool => !in_array(strtolower($address), $excluded, true),
        ));
    }

    private function prefixedSubject(string $prefix, string $subject): string
    {
        return str_starts_with(strtolower($subject), strtolower($prefix))
            ? $subject
            : $prefix . ' ' . $subject;
    }

    private function replyBody(Message $message): string
    {
        $source = $message->textBody !== '' ? $message->textBody : trim(strip_tags($message->htmlBody));
        $quoted = preg_replace('/^/m', '> ', $source) ?? $source;

        return "\n\nOn " . $message->date . ', ' . $message->from . " wrote:\n" . $quoted;
    }

    private function forwardBody(Message $message): string
    {
        $source = $message->textBody !== '' ? $message->textBody : trim(strip_tags($message->htmlBody));
        $ccLine = $message->cc !== '' ? 'Cc: ' . $message->cc . "\n" : '';

        return "\n\n---------- Forwarded message ----------\n"
            . 'From: ' . $message->from . "\n"
            . 'To: ' . $message->to . "\n"
            . $ccLine
            . 'Date: ' . $message->date . "\n"
            . 'Subject: ' . $message->subject . "\n\n"
            . $source;
    }

    private function sourceMessageId(string $value): ?string
    {
        $value = trim($value, '<> ');
        if ($value === '' || strlen($value) > 255 || !Validator::headerText($value)) {
            return null;
        }

        return $value;
    }
}

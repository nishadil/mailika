<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Filter\SievePublishException;
use Mailika\Filter\SievePublisherInterface;
use Mailika\Filter\SieveRule;
use Mailika\Filter\SieveRuleRepositoryInterface;
use Mailika\Filter\SieveScriptImportException;
use Mailika\Filter\SieveScriptAnalysis;
use Mailika\Filter\SieveScriptAnalyzer;
use Mailika\Filter\SieveScriptCompiler;
use Mailika\Filter\SieveScriptImporter;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Security\CsrfTokenManager;
use Mailika\Support\View;

final readonly class FilterController
{
    /** @var array<string, string> */
    private const MATCH_FIELDS = [
        'from' => 'From',
        'to' => 'To or Cc',
        'subject' => 'Subject',
        'body' => 'Body',
        'any_header' => 'Any common header',
    ];

    /** @var array<string, string> */
    private const MATCH_OPERATORS = [
        'contains' => 'Contains',
        'is' => 'Is exactly',
    ];

    /** @var array<string, string> */
    private const ACTIONS = [
        'fileinto' => 'Move to folder',
        'redirect' => 'Redirect',
        'vacation' => 'Auto-reply',
        'keep' => 'Keep',
        'discard' => 'Discard',
    ];

    public function __construct(
        private View $view,
        private CsrfTokenManager $csrf,
        private CredentialVault $vault,
        private SieveRuleRepositoryInterface $rules,
        private SieveScriptCompiler $compiler,
        private SieveScriptAnalyzer $analyzer,
        private SieveScriptImporter $importer,
        private SievePublisherInterface $publisher,
        private AuditLogger $audit,
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
            return Response::redirect('/filters');
        }

        $rule = $this->ruleFromRequest($request);
        if (!$rule instanceof SieveRule) {
            return $this->render(
                $credentials->email,
                'Enter a filter name, match value, and action target when the selected action requires one.',
                422,
            );
        }

        $this->rules->save($credentials->email, $rule);
        $this->audit->record('filter.saved', [
            'mailbox' => $credentials->email,
            'rule' => $rule->name,
            'enabled' => $rule->enabled ? 'true' : 'false',
            'match_field' => $rule->matchField,
            'action' => $rule->action,
            'stop_processing' => $rule->stopProcessing ? 'true' : 'false',
        ]);

        return Response::redirect('/filters');
    }

    public function publish(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return Response::redirect('/filters');
        }

        $script = $this->compiler->compile($this->rules->listForMailbox($credentials->email));

        try {
            $result = $this->publisher->publish($credentials, $script);
            $this->audit->record('filter.published', [
                'mailbox' => $credentials->email,
                'script_name' => $this->publisher->scriptName(),
            ]);

            return $this->render($credentials->email, null, 200, $result->message);
        } catch (SievePublishException) {
            $this->audit->record('filter.publish_failed', [
                'mailbox' => $credentials->email,
                'script_name' => $this->publisher->scriptName(),
            ]);

            return $this->render(
                $credentials->email,
                'Filters could not be published to the ManageSieve server.',
                502,
            );
        }
    }

    public function inspect(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return Response::redirect('/filters');
        }

        $script = $request->input('sieve_script');
        $analysis = $this->analyzer->analyze($script);

        $this->audit->record('filter.script_inspected', [
            'mailbox' => $credentials->email,
            'bytes' => (string) $analysis->bytes,
            'mailika_owned' => $analysis->mailikaOwned ? 'true' : 'false',
            'warnings' => (string) count($analysis->warnings),
        ]);

        return $this->render($credentials->email, null, 200, 'Sieve script inspected.', $analysis, $script);
    }

    public function import(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return Response::redirect('/filters');
        }

        $script = $request->input('sieve_script');
        $analysis = $this->analyzer->analyze($script);

        try {
            $rules = $this->importer->import($script);
        } catch (SieveScriptImportException) {
            $this->audit->record('filter.script_import_failed', [
                'mailbox' => $credentials->email,
                'mailika_owned' => $analysis->mailikaOwned ? 'true' : 'false',
                'warnings' => (string) count($analysis->warnings),
            ]);

            return $this->render(
                $credentials->email,
                'Only Mailika-generated Sieve scripts can be imported automatically.',
                422,
                null,
                $analysis,
                $script,
            );
        }

        foreach ($rules as $rule) {
            $this->rules->save($credentials->email, $rule);
        }

        $this->audit->record('filter.script_imported', [
            'mailbox' => $credentials->email,
            'rules' => (string) count($rules),
        ]);

        $notice = $rules === []
            ? 'No enabled filters were imported.'
            : 'Imported ' . count($rules) . ' Mailika-generated filter(s).';

        return $this->render($credentials->email, null, 200, $notice);
    }

    public function delete(Request $request): Response
    {
        $credentials = $this->vault->current();
        if ($credentials === null) {
            return Response::redirect('/login');
        }

        if (!$this->csrf->validate($request->input('_csrf'))) {
            return Response::redirect('/filters');
        }

        $id = $request->intInput('id');
        if ($id > 0) {
            $rule = $this->ruleById($credentials->email, $id);
            $this->rules->deleteForMailbox($credentials->email, $id);
            $this->audit->record('filter.deleted', [
                'mailbox' => $credentials->email,
                'rule' => $rule?->name,
            ]);
        }

        return Response::redirect('/filters');
    }

    private function render(
        string $mailboxIdentity,
        ?string $error = null,
        int $status = 200,
        ?string $notice = null,
        ?SieveScriptAnalysis $analysis = null,
        ?string $inspectedScript = null,
    ): Response {
        $rules = $this->rules->listForMailbox($mailboxIdentity);

        return new Response($this->view->render('filters/index', [
            'title' => 'Mail filters',
            'csrfToken' => $this->csrf->token(),
            'rules' => $rules,
            'script' => $this->compiler->compile($rules),
            'matchFields' => self::MATCH_FIELDS,
            'matchOperators' => self::MATCH_OPERATORS,
            'actions' => self::ACTIONS,
            'error' => $error,
            'notice' => $notice,
            'analysis' => $analysis,
            'inspectedScript' => $inspectedScript,
            'publisherConfigured' => $this->publisher->configured(),
            'scriptName' => $this->publisher->scriptName(),
        ]), $status);
    }

    private function ruleFromRequest(Request $request): ?SieveRule
    {
        $name = $this->text($request->input('name'), 128);
        $field = $this->choice($request->input('match_field'), array_keys(self::MATCH_FIELDS));
        $operator = $this->choice($request->input('match_operator'), array_keys(self::MATCH_OPERATORS));
        $value = $this->text($request->input('match_value'), 512);
        $action = $this->choice($request->input('action'), array_keys(self::ACTIONS));
        $target = $this->actionTarget($action, $request->input('action_target'));
        $vacationDays = $this->vacationDays($action, $request->input('vacation_days'));
        $vacationSubject = $action === 'vacation'
            ? $this->optionalText($request->input('vacation_subject'), 255)
            : null;
        $vacationExcludedSenders = $this->vacationExcludedSenders(
            $action,
            $request->input('vacation_excluded_senders'),
        );
        $vacationAddresses = $this->vacationEmailList($action, $request->input('vacation_addresses'));

        if ($name === null || $field === null || $operator === null || $value === null || $action === null) {
            return null;
        }

        if ($vacationExcludedSenders === null || $vacationAddresses === null) {
            return null;
        }

        if (
            in_array($action, ['fileinto', 'redirect', 'vacation'], true)
            && $target === null
        ) {
            return null;
        }

        if ($action === 'vacation' && $request->input('vacation_days') !== '' && $vacationDays === null) {
            return null;
        }

        return new SieveRule(
            $name,
            $request->input('enabled') === '1',
            $field,
            $operator,
            $value,
            $action,
            $target,
            $request->input('stop_processing') === '1',
            $request->intInput('id') > 0 ? $request->intInput('id') : null,
            $vacationDays,
            $vacationSubject,
            $vacationExcludedSenders,
            $vacationAddresses,
        );
    }

    /**
     * @param list<string> $allowed
     */
    private function choice(string $value, array $allowed): ?string
    {
        return in_array($value, $allowed, true) ? $value : null;
    }

    private function text(string $value, int $maxLength): ?string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            return null;
        }

        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1 ? null : $value;
    }

    private function optionalText(string $value, int $maxLength): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $this->text($value, $maxLength);
    }

    private function vacationDays(?string $action, string $value): ?int
    {
        if ($action !== 'vacation' || $value === '') {
            return null;
        }

        if (!ctype_digit($value)) {
            return null;
        }

        $days = (int) $value;
        return $days >= 1 && $days <= 365 ? $days : null;
    }

    /**
     * @return list<string>|null
     */
    private function vacationExcludedSenders(?string $action, string $value): ?array
    {
        return $this->vacationEmailList($action, $value);
    }

    /**
     * @return list<string>|null
     */
    private function vacationEmailList(?string $action, string $value): ?array
    {
        if ($action !== 'vacation') {
            return [];
        }

        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $items = preg_split('/[\s,;]+/', $value) ?: [];
        $senders = [];

        foreach ($items as $item) {
            $item = mb_strtolower(trim($item));
            if ($item === '') {
                continue;
            }

            if (filter_var($item, FILTER_VALIDATE_EMAIL) === false || mb_strlen($item) > 320) {
                return null;
            }

            $senders[$item] = $item;
        }

        if (count($senders) > 20) {
            return null;
        }

        return array_values($senders);
    }

    private function actionTarget(?string $action, string $value): ?string
    {
        if ($action === null || in_array($action, ['keep', 'discard'], true)) {
            return null;
        }

        $target = $this->text($value, 512);
        if ($target === null) {
            return null;
        }

        if ($action === 'redirect' && filter_var($target, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $target;
    }

    private function ruleById(string $mailboxIdentity, int $id): ?SieveRule
    {
        foreach ($this->rules->listForMailbox($mailboxIdentity) as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        return null;
    }
}

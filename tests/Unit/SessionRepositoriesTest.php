<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Contact\Contact;
use Mailika\Contact\ContactGroup;
use Mailika\Contact\SessionContactGroupRepository;
use Mailika\Contact\SessionContactRepository;
use Mailika\Draft\DraftMessage;
use Mailika\Draft\SessionDraftRepository;
use Mailika\Filter\SessionSieveRuleRepository;
use Mailika\Filter\SieveRule;
use Mailika\Identity\Identity;
use Mailika\Identity\SessionIdentityRepository;
use Mailika\Mail\MailAccountProfile;
use Mailika\Mail\SavedSearch;
use Mailika\Mail\MessageEnvelope;
use Mailika\Mail\MessageSearchCriteria;
use Mailika\Mail\SessionMailAccountProfileRepository;
use Mailika\Mail\SessionMessageMetadataCacheRepository;
use Mailika\Mail\SessionSavedSearchRepository;
use Mailika\Preferences\Preferences;
use Mailika\Preferences\SessionPreferencesRepository;
use PHPUnit\Framework\TestCase;

final class SessionRepositoriesTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testContactsAreScopedByMailboxIdentity(): void
    {
        $repository = new SessionContactRepository();
        $repository->save('one@example.com', new Contact('One', 'one-contact@example.com'));
        $repository->save('two@example.com', new Contact('Two', 'two-contact@example.com'));

        $contacts = $repository->listForMailbox('one@example.com');

        self::assertCount(1, $contacts);
        self::assertSame('one-contact@example.com', $contacts[0]->email);
    }

    public function testContactSaveUpdatesExistingMailboxEmailAndPreservesGroups(): void
    {
        $contacts = new SessionContactRepository();
        $groups = new SessionContactGroupRepository();
        $groups->save('user@example.com', new ContactGroup('Team'));
        $group = $groups->listForMailbox('user@example.com')[0];

        $contacts->save('user@example.com', new Contact('Old', 'ALICE@example.com', 'old'));
        $contacts->save('other@example.com', new Contact('Other', 'alice@example.com'));
        $groups->assignContactByEmail('user@example.com', 'alice@example.com', (int) $group->id);

        $contacts->save('user@example.com', new Contact('Alice', 'alice@example.com', 'new'));

        $stored = $contacts->listForMailbox('user@example.com');
        self::assertCount(1, $stored);
        self::assertSame('Alice', $stored[0]->displayName);
        self::assertSame('alice@example.com', $stored[0]->email);
        self::assertSame('new', $stored[0]->notes);
        self::assertSame(['Team'], $stored[0]->groups);
        self::assertCount(1, $contacts->listForMailbox('other@example.com'));
    }

    public function testOnlyOneSessionIdentityRemainsDefault(): void
    {
        $repository = new SessionIdentityRepository();
        $repository->save('user@example.com', new Identity('Primary', 'primary@example.com', null, true));
        $repository->save('user@example.com', new Identity('Alias', 'alias@example.com', null, true));

        $identities = $repository->listForMailbox('user@example.com');

        self::assertTrue($identities[0]->default);
        self::assertSame('alias@example.com', $identities[0]->email);
        self::assertFalse($identities[1]->default);
        self::assertNull($identities[1]->replyTo);
    }

    public function testIdentitySaveUpdatesExistingMailboxEmailAndEnsuresDefault(): void
    {
        $repository = new SessionIdentityRepository();
        $repository->save('user@example.com', new Identity('Old', 'ALIAS@example.com', null, true));
        $repository->save('other@example.com', new Identity('Other', 'alias@example.com', null, true));

        $repository->save('user@example.com', new Identity('Alias', 'alias@example.com', 'reply@example.com'));

        $identities = $repository->listForMailbox('user@example.com');
        self::assertCount(1, $identities);
        self::assertSame('Alias', $identities[0]->displayName);
        self::assertSame('alias@example.com', $identities[0]->email);
        self::assertSame('reply@example.com', $identities[0]->replyTo);
        self::assertTrue($identities[0]->default);
        self::assertCount(1, $repository->listForMailbox('other@example.com'));
    }

    public function testIdentityDeletionIsScopedAndPromotesRemainingDefault(): void
    {
        $repository = new SessionIdentityRepository();
        $repository->save('user@example.com', new Identity('Primary', 'primary@example.com', null, true));
        $repository->save('user@example.com', new Identity('Alias', 'alias@example.com'));
        $repository->save('other@example.com', new Identity('Other', 'other@example.com', null, true));

        $primary = $repository->listForMailbox('user@example.com')[0];
        $repository->deleteForMailbox('other@example.com', (int) $primary->id);

        self::assertCount(2, $repository->listForMailbox('user@example.com'));
        self::assertSame([], $repository->listForMailbox('other@example.com'));

        $repository->deleteForMailbox('user@example.com', (int) $primary->id);
        $userIdentities = $repository->listForMailbox('user@example.com');

        self::assertCount(1, $userIdentities);
        self::assertSame('alias@example.com', $userIdentities[0]->email);
        self::assertTrue($userIdentities[0]->default);
    }

    public function testDraftsCanBeListedAndFoundForMailbox(): void
    {
        $repository = new SessionDraftRepository();
        $repository->save('user@example.com', new DraftMessage('', 'to@example.com', '', '', 'Subject', 'Body', ''));

        $drafts = $repository->listForMailbox('user@example.com');

        self::assertCount(1, $drafts);
        self::assertSame('Subject', $drafts[0]->subject);
        self::assertSame('Body', $repository->findForMailbox('user@example.com', $drafts[0]->id)?->body);
        self::assertNull($repository->findForMailbox('other@example.com', $drafts[0]->id));
    }

    public function testDraftDeletionIsScopedByMailboxIdentity(): void
    {
        $repository = new SessionDraftRepository();
        $repository->save('user@example.com', new DraftMessage('', 'one@example.com', '', '', 'One', 'Body', ''));
        $repository->save('other@example.com', new DraftMessage('', 'two@example.com', '', '', 'Two', 'Body', ''));

        $draft = $repository->listForMailbox('user@example.com')[0];
        $repository->deleteForMailbox('other@example.com', $draft->id);

        self::assertCount(1, $repository->listForMailbox('user@example.com'));

        $repository->deleteForMailbox('user@example.com', $draft->id);

        self::assertSame([], $repository->listForMailbox('user@example.com'));
        self::assertCount(1, $repository->listForMailbox('other@example.com'));
    }

    public function testDraftSaveUpdatesExistingMailboxDraft(): void
    {
        $repository = new SessionDraftRepository();
        $repository->save('user@example.com', new DraftMessage('', 'old@example.com', '', '', 'Old', 'Old body', ''));
        $repository->save(
            'other@example.com',
            new DraftMessage('', 'other@example.com', '', '', 'Other', 'Other body', ''),
        );
        $draft = $repository->listForMailbox('user@example.com')[0];

        $repository->save(
            'user@example.com',
            new DraftMessage(
                $draft->id,
                'new@example.com',
                'cc@example.com',
                '',
                'New',
                'New body',
                '',
                'alias@example.com',
            ),
        );

        $drafts = $repository->listForMailbox('user@example.com');
        self::assertCount(1, $drafts);
        self::assertSame($draft->id, $drafts[0]->id);
        self::assertSame('new@example.com', $drafts[0]->to);
        self::assertSame('cc@example.com', $drafts[0]->cc);
        self::assertSame('New', $drafts[0]->subject);
        self::assertSame('New body', $drafts[0]->body);
        self::assertSame('alias@example.com', $drafts[0]->identityEmail);
        self::assertCount(1, $repository->listForMailbox('other@example.com'));
    }

    public function testContactGroupsCanBeAssignedToContacts(): void
    {
        $contacts = new SessionContactRepository();
        $groups = new SessionContactGroupRepository();

        $groups->save('user@example.com', new ContactGroup('Team'));
        $group = $groups->listForMailbox('user@example.com')[0];
        $contacts->save('user@example.com', new Contact('Alice', 'alice@example.com'));
        $groups->assignContactByEmail('user@example.com', 'alice@example.com', (int) $group->id);

        $stored = $contacts->listForMailbox('user@example.com');

        self::assertSame(['Team'], $stored[0]->groups);
    }

    public function testContactGroupSaveUpdatesExistingMailboxNameAndKeepsMemberships(): void
    {
        $contacts = new SessionContactRepository();
        $groups = new SessionContactGroupRepository();
        $groups->save('user@example.com', new ContactGroup('Team'));
        $groups->save('other@example.com', new ContactGroup('Team'));
        $group = $groups->listForMailbox('user@example.com')[0];
        $contacts->save('user@example.com', new Contact('Alice', 'alice@example.com'));
        $groups->assignContactByEmail('user@example.com', 'alice@example.com', (int) $group->id);

        $groups->save('user@example.com', new ContactGroup('team'));

        $storedGroups = $groups->listForMailbox('user@example.com');
        self::assertCount(1, $storedGroups);
        self::assertSame('team', $storedGroups[0]->name);
        self::assertSame((int) $group->id, $storedGroups[0]->id);
        self::assertSame(['team'], $contacts->listForMailbox('user@example.com')[0]->groups);
        self::assertSame('Team', $groups->listForMailbox('other@example.com')[0]->name);
    }

    public function testContactDeletionIsScopedByMailboxIdentity(): void
    {
        $repository = new SessionContactRepository();
        $repository->save('user@example.com', new Contact('Alice', 'alice@example.com'));
        $repository->save('other@example.com', new Contact('Alice', 'alice@example.com'));

        $repository->deleteForMailbox('other@example.com', 'other@example.com');

        self::assertCount(1, $repository->listForMailbox('user@example.com'));
        self::assertCount(1, $repository->listForMailbox('other@example.com'));

        $repository->deleteForMailbox('user@example.com', 'alice@example.com');

        self::assertSame([], $repository->listForMailbox('user@example.com'));
        self::assertCount(1, $repository->listForMailbox('other@example.com'));
    }

    public function testContactGroupDeletionIsScopedAndRemovesMemberships(): void
    {
        $contacts = new SessionContactRepository();
        $groups = new SessionContactGroupRepository();

        $groups->save('user@example.com', new ContactGroup('Team'));
        $groups->save('other@example.com', new ContactGroup('Other Team'));
        $group = $groups->listForMailbox('user@example.com')[0];
        $otherGroup = $groups->listForMailbox('other@example.com')[0];
        $contacts->save('user@example.com', new Contact('Alice', 'alice@example.com'));
        $contacts->save('other@example.com', new Contact('Bob', 'bob@example.com'));
        $groups->assignContactByEmail('user@example.com', 'alice@example.com', (int) $group->id);
        $groups->assignContactByEmail('other@example.com', 'bob@example.com', (int) $otherGroup->id);

        $groups->deleteForMailbox('other@example.com', (int) $group->id);

        self::assertSame(['Team'], $contacts->listForMailbox('user@example.com')[0]->groups);
        self::assertCount(1, $groups->listForMailbox('other@example.com'));

        $groups->deleteForMailbox('user@example.com', (int) $group->id);

        self::assertSame([], $groups->listForMailbox('user@example.com'));
        self::assertSame([], $contacts->listForMailbox('user@example.com')[0]->groups);
        self::assertSame(['Other Team'], $contacts->listForMailbox('other@example.com')[0]->groups);
    }

    public function testSavedSearchesAreScopedAndSorted(): void
    {
        $repository = new SessionSavedSearchRepository();
        $repository->save('user@example.com', new SavedSearch('Zebra', 'INBOX', 'z'));
        $repository->save('user@example.com', new SavedSearch('Alpha', 'Archive', 'a'));
        $repository->save('other@example.com', new SavedSearch('Other', 'INBOX', 'o'));

        $searches = $repository->listForMailbox('user@example.com');

        self::assertCount(2, $searches);
        self::assertSame('Alpha', $searches[0]->name);
        self::assertSame('Archive', $searches[0]->folder);
        self::assertSame('Zebra', $searches[1]->name);
    }

    public function testSavedSearchSaveUpdatesExistingMailboxName(): void
    {
        $repository = new SessionSavedSearchRepository();
        $repository->save('user@example.com', new SavedSearch('Invoices', 'INBOX', 'invoice'));
        $repository->save('other@example.com', new SavedSearch('Invoices', 'INBOX', 'other'));

        $repository->save('user@example.com', new SavedSearch('invoices', 'Archive', 'from:billing'));

        $searches = $repository->listForMailbox('user@example.com');
        self::assertCount(1, $searches);
        self::assertSame('invoices', $searches[0]->name);
        self::assertSame('Archive', $searches[0]->folder);
        self::assertSame('from:billing', $searches[0]->query);
        self::assertCount(1, $repository->listForMailbox('other@example.com'));
    }

    public function testSavedSearchDeletionIsScopedByMailboxIdentity(): void
    {
        $repository = new SessionSavedSearchRepository();
        $repository->save('user@example.com', new SavedSearch('Zebra', 'INBOX', 'z'));
        $repository->save('user@example.com', new SavedSearch('Alpha', 'Archive', 'a'));
        $repository->save('other@example.com', new SavedSearch('Other', 'INBOX', 'o'));

        $zebra = array_values(array_filter(
            $repository->listForMailbox('user@example.com'),
            static fn (SavedSearch $search): bool => $search->name === 'Zebra',
        ))[0];
        $repository->deleteForMailbox('other@example.com', (int) $zebra->id);

        self::assertCount(2, $repository->listForMailbox('user@example.com'));
        self::assertSame([], $repository->listForMailbox('other@example.com'));

        $repository->deleteForMailbox('user@example.com', (int) $zebra->id);
        $searches = $repository->listForMailbox('user@example.com');

        self::assertCount(1, $searches);
        self::assertSame('Alpha', $searches[0]->name);
    }

    public function testSieveRulesAreScopedAndSorted(): void
    {
        $repository = new SessionSieveRuleRepository();
        $repository->save(
            'user@example.com',
            new SieveRule('Zebra', true, 'subject', 'contains', 'z', 'fileinto', 'Archive'),
        );
        $repository->save(
            'user@example.com',
            new SieveRule('Alpha', false, 'from', 'is', 'alice@example.com', 'keep'),
        );
        $repository->save(
            'other@example.com',
            new SieveRule('Other', true, 'subject', 'contains', 'o', 'discard'),
        );

        $rules = $repository->listForMailbox('user@example.com');

        self::assertCount(2, $rules);
        self::assertSame('Alpha', $rules[0]->name);
        self::assertFalse($rules[0]->enabled);
        self::assertSame('Zebra', $rules[1]->name);
        self::assertSame('Archive', $rules[1]->actionTarget);
    }

    public function testSieveRuleSaveUpdatesExistingMailboxName(): void
    {
        $repository = new SessionSieveRuleRepository();
        $repository->save(
            'user@example.com',
            new SieveRule('Invoices', true, 'subject', 'contains', 'invoice', 'fileinto', 'Archive'),
        );
        $repository->save(
            'other@example.com',
            new SieveRule('Invoices', true, 'subject', 'contains', 'other', 'discard'),
        );

        $repository->save(
            'user@example.com',
            new SieveRule('invoices', false, 'from', 'is', 'billing@example.com', 'discard', null, false),
        );

        $rules = $repository->listForMailbox('user@example.com');
        self::assertCount(1, $rules);
        self::assertSame('invoices', $rules[0]->name);
        self::assertFalse($rules[0]->enabled);
        self::assertSame('from', $rules[0]->matchField);
        self::assertSame('discard', $rules[0]->action);
        self::assertFalse($rules[0]->stopProcessing);
        self::assertCount(1, $repository->listForMailbox('other@example.com'));
    }

    public function testSieveVacationOptionsPersistInSessionRepository(): void
    {
        $repository = new SessionSieveRuleRepository();
        $repository->save(
            'user@example.com',
            new SieveRule(
                'Away',
                true,
                'to',
                'is',
                'user@example.com',
                'vacation',
                'I am away.',
                true,
                null,
                14,
                'Away from mail',
                ['noreply@example.com'],
                ['user@example.com', 'team@example.com'],
            ),
        );

        $rules = $repository->listForMailbox('user@example.com');

        self::assertCount(1, $rules);
        self::assertSame(14, $rules[0]->vacationDays);
        self::assertSame('Away from mail', $rules[0]->vacationSubject);
        self::assertSame(['noreply@example.com'], $rules[0]->vacationExcludedSenders);
        self::assertSame(['user@example.com', 'team@example.com'], $rules[0]->vacationAddresses);
    }

    public function testSieveRuleDeletionIsScopedByMailboxIdentity(): void
    {
        $repository = new SessionSieveRuleRepository();
        $repository->save(
            'user@example.com',
            new SieveRule('Zebra', true, 'subject', 'contains', 'z', 'fileinto', 'Archive'),
        );
        $repository->save(
            'other@example.com',
            new SieveRule('Other', true, 'subject', 'contains', 'o', 'discard'),
        );

        $rule = $repository->listForMailbox('user@example.com')[0];
        $repository->deleteForMailbox('other@example.com', (int) $rule->id);

        self::assertCount(1, $repository->listForMailbox('user@example.com'));
        self::assertSame([], $repository->listForMailbox('other@example.com'));

        $repository->deleteForMailbox('user@example.com', (int) $rule->id);

        self::assertSame([], $repository->listForMailbox('user@example.com'));
    }

    public function testAccountProfilesAreScopedAndSorted(): void
    {
        $repository = new SessionMailAccountProfileRepository();
        $repository->save(
            'user@example.com',
            new MailAccountProfile(
                'Work',
                'work@example.com',
                'imap.work.example.com',
                993,
                true,
                'smtp.work.example.com',
                587,
                'starttls',
            ),
        );
        $repository->save(
            'user@example.com',
            new MailAccountProfile(
                'Archive',
                'archive@example.com',
                'imap.archive.example.com',
                993,
                true,
                'smtp.archive.example.com',
                465,
                'smtps',
            ),
        );
        $repository->save(
            'other@example.com',
            new MailAccountProfile(
                'Other',
                'other@example.com',
                'imap.other.example.com',
                993,
                true,
                'smtp.other.example.com',
                587,
                'starttls',
            ),
        );

        $profiles = $repository->listForMailbox('user@example.com');

        self::assertCount(2, $profiles);
        self::assertSame('Archive', $profiles[0]->label);
        self::assertSame('archive@example.com', $profiles[0]->email);
        self::assertSame('smtps', $profiles[0]->smtpTls);
        self::assertSame('Work', $profiles[1]->label);
        self::assertCount(1, $repository->listForMailbox('other@example.com'));
    }

    public function testAccountProfileSaveUpdatesExistingMailboxEmail(): void
    {
        $repository = new SessionMailAccountProfileRepository();
        $repository->save(
            'user@example.com',
            new MailAccountProfile(
                'Old',
                'ALIAS@example.com',
                'imap.old.example.com',
                993,
                true,
                'smtp.old.example.com',
                587,
                'starttls',
            ),
        );
        $repository->save(
            'other@example.com',
            new MailAccountProfile(
                'Other',
                'alias@example.com',
                'imap.other.example.com',
                993,
                true,
                'smtp.other.example.com',
                587,
                'starttls',
            ),
        );

        $repository->save(
            'user@example.com',
            new MailAccountProfile(
                'Alias',
                'alias@example.com',
                'imap.alias.example.com',
                143,
                false,
                'smtp.alias.example.com',
                25,
                'none',
            ),
        );

        $profiles = $repository->listForMailbox('user@example.com');
        self::assertCount(1, $profiles);
        self::assertSame('Alias', $profiles[0]->label);
        self::assertSame('alias@example.com', $profiles[0]->email);
        self::assertSame('imap.alias.example.com', $profiles[0]->imapHost);
        self::assertSame(143, $profiles[0]->imapPort);
        self::assertFalse($profiles[0]->imapTls);
        self::assertSame('none', $profiles[0]->smtpTls);
        self::assertCount(1, $repository->listForMailbox('other@example.com'));
    }

    public function testAccountProfileDeletionIsScopedByMailboxIdentity(): void
    {
        $repository = new SessionMailAccountProfileRepository();
        $repository->save(
            'user@example.com',
            new MailAccountProfile(
                'Primary',
                'primary@example.com',
                'imap.primary.example.com',
                993,
                true,
                'smtp.primary.example.com',
                587,
                'starttls',
            ),
        );
        $repository->save(
            'other@example.com',
            new MailAccountProfile(
                'Other',
                'other@example.com',
                'imap.other.example.com',
                993,
                true,
                'smtp.other.example.com',
                587,
                'starttls',
            ),
        );

        $primary = $repository->listForMailbox('user@example.com')[0];
        $repository->deleteForMailbox('other@example.com', (int) $primary->id);

        self::assertCount(1, $repository->listForMailbox('user@example.com'));
        self::assertSame([], $repository->listForMailbox('other@example.com'));

        $repository->deleteForMailbox('user@example.com', (int) $primary->id);

        self::assertSame([], $repository->listForMailbox('user@example.com'));
    }

    public function testPreferencesAreScopedByMailboxIdentity(): void
    {
        $repository = new SessionPreferencesRepository();
        $repository->save('one@example.com', new Preferences('en-US', 'Asia/Kolkata', true, 'dark', false, 100));

        $one = $repository->get('one@example.com');
        $two = $repository->get('two@example.com');

        self::assertSame('en-US', $one->locale);
        self::assertSame('Asia/Kolkata', $one->timezone);
        self::assertTrue($one->remoteImages);
        self::assertSame('dark', $one->theme);
        self::assertFalse($one->threadedListing);
        self::assertSame(100, $one->messagesPerPage);
        self::assertSame('UTC', $two->timezone);
        self::assertFalse($two->remoteImages);
        self::assertSame('system', $two->theme);
        self::assertTrue($two->threadedListing);
        self::assertSame(50, $two->messagesPerPage);
    }

    public function testMessageMetadataCacheSupportsPaginationAndSearch(): void
    {
        $repository = new SessionMessageMetadataCacheRepository();
        $repository->store('user@example.com', 'INBOX', [
            new MessageEnvelope('1', 'alice@example.com', 'Invoice January', '2026-01-01', false, true),
            new MessageEnvelope('2', 'bob@example.com', 'Meeting notes', '2026-01-02', true, false, true),
            new MessageEnvelope('3', 'billing@example.com', 'Invoice February', '2026-02-01', true, false),
        ]);

        $firstPage = $repository->list('user@example.com', 'INBOX', 2, 1);
        $secondPage = $repository->list('user@example.com', 'INBOX', 2, 2);
        $search = $repository->list('user@example.com', 'INBOX', 50, 1, 'invoice');

        self::assertCount(2, $firstPage);
        self::assertCount(1, $secondPage);
        self::assertSame('3', $firstPage[0]->id);
        self::assertTrue($firstPage[1]->flagged);
        self::assertCount(2, $search);
        self::assertSame('billing@example.com', $search[0]->from);
        self::assertSame([], $repository->list('other@example.com', 'INBOX'));
    }

    public function testMessageMetadataCacheSupportsAdvancedSearchCriteria(): void
    {
        $repository = new SessionMessageMetadataCacheRepository();
        $repository->store('user@example.com', 'INBOX', [
            new MessageEnvelope('1', 'alice@example.com', 'Invoice January', '2026-01-01', false, true),
            new MessageEnvelope('2', 'bob@example.com', 'Meeting notes', '2026-01-02', true, false, true),
            new MessageEnvelope('3', 'billing@example.com', 'Invoice February', '2026-02-01', true, false),
        ]);

        $fromAndSubject = $repository->list(
            'user@example.com',
            'INBOX',
            criteria: MessageSearchCriteria::fromRawInput('INBOX', '', 1, 50, 'billing@example.com', '', 'Invoice'),
        );
        $unreadInvoice = $repository->list(
            'user@example.com',
            'INBOX',
            criteria: MessageSearchCriteria::fromRawInput('INBOX', 'is:unread invoice', 1, 50),
        );
        $flagged = $repository->list(
            'user@example.com',
            'INBOX',
            criteria: MessageSearchCriteria::fromRawInput('INBOX', 'is:flagged', 1, 50),
        );

        self::assertCount(1, $fromAndSubject);
        self::assertSame('3', $fromAndSubject[0]->id);
        self::assertCount(1, $unreadInvoice);
        self::assertSame('1', $unreadInvoice[0]->id);
        self::assertCount(1, $flagged);
        self::assertSame('2', $flagged[0]->id);
    }

    public function testMessageMetadataCacheMutationsKeepFallbackStateCurrent(): void
    {
        $repository = new SessionMessageMetadataCacheRepository();
        $repository->store('user@example.com', 'INBOX', [
            new MessageEnvelope('1', 'alice@example.com', 'Invoice January', '2026-01-01', false, true),
            new MessageEnvelope('2', 'bob@example.com', 'Meeting notes', '2026-01-02', true, false),
        ]);

        $repository->updateFlags('user@example.com', 'INBOX', '1', seen: true, flagged: true);
        $updated = $repository->list('user@example.com', 'INBOX', 50, 1, 'invoice')[0];
        self::assertTrue($updated->seen);
        self::assertTrue($updated->flagged);

        $repository->copy('user@example.com', 'INBOX', '1', 'Archive');
        self::assertCount(1, $repository->list('user@example.com', 'Archive'));
        self::assertCount(2, $repository->list('user@example.com', 'INBOX'));

        $repository->move('user@example.com', 'INBOX', '1', 'Sent');
        self::assertSame('1', $repository->list('user@example.com', 'Sent')[0]->id);
        self::assertCount(1, $repository->list('user@example.com', 'INBOX'));

        $repository->delete('user@example.com', 'INBOX', '2');
        self::assertSame([], $repository->list('user@example.com', 'INBOX'));
    }

    public function testMessageMetadataCachePrunesOldestRowsPerFolder(): void
    {
        $repository = new SessionMessageMetadataCacheRepository();
        $messages = [];

        for ($i = 1; $i <= 10_050; $i++) {
            $messages[] = new MessageEnvelope(
                (string) $i,
                'sender@example.com',
                'Subject #' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                '2026-01-01',
                true,
                false,
            );
        }

        $repository->store('user@example.com', 'INBOX', $messages);

        self::assertCount(10_000, $repository->list('user@example.com', 'INBOX', 10_000));
        self::assertSame('10050', $repository->list('user@example.com', 'INBOX', 1)[0]->id);
        self::assertSame([], $repository->list('user@example.com', 'INBOX', 50, 1, 'Subject #00001'));
    }
}

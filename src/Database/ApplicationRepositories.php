<?php

declare(strict_types=1);

namespace Mailika\Database;

use Mailika\Audit\AuditEventRepositoryInterface;
use Mailika\Contact\ContactGroupRepositoryInterface;
use Mailika\Contact\ContactRepositoryInterface;
use Mailika\Draft\DraftRepositoryInterface;
use Mailika\Filter\SieveRuleRepositoryInterface;
use Mailika\Identity\IdentityRepositoryInterface;
use Mailika\Mail\MailAccountProfileRepositoryInterface;
use Mailika\Mail\MessageMetadataCacheRepositoryInterface;
use Mailika\Mail\SavedSearchRepositoryInterface;
use Mailika\Preferences\PreferencesRepositoryInterface;

final readonly class ApplicationRepositories
{
    public function __construct(
        public ContactRepositoryInterface $contacts,
        public ContactGroupRepositoryInterface $contactGroups,
        public IdentityRepositoryInterface $identities,
        public DraftRepositoryInterface $drafts,
        public SieveRuleRepositoryInterface $sieveRules,
        public MailAccountProfileRepositoryInterface $mailAccountProfiles,
        public SavedSearchRepositoryInterface $savedSearches,
        public MessageMetadataCacheRepositoryInterface $messageMetadataCache,
        public PreferencesRepositoryInterface $preferences,
        public AuditEventRepositoryInterface $auditEvents,
    ) {
    }
}

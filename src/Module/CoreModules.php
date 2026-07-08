<?php

declare(strict_types=1);

namespace Mailika\Module;

use Mailika\Config\Config;

final readonly class CoreModules
{
    public static function fromConfig(Config $config): InternalModuleRegistry
    {
        return new InternalModuleRegistry([
            new InternalModule('auth'),
            new InternalModule('mail'),
            new InternalModule('compose'),
            new InternalModule('contacts'),
            new InternalModule('identities'),
            new InternalModule('drafts'),
            new InternalModule('filters', $config->bool('sieve.enabled')),
            new InternalModule('accounts'),
            new InternalModule('preferences'),
            new InternalModule('audit'),
            new InternalModule('fixture-mailbox', $config->string('mail.imap_adapter') === 'fixture'),
        ]);
    }
}

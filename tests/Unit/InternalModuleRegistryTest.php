<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use InvalidArgumentException;
use Mailika\Config\Config;
use Mailika\Module\CoreModules;
use Mailika\Module\InternalModule;
use Mailika\Module\InternalModuleRegistry;
use PHPUnit\Framework\TestCase;

final class InternalModuleRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['APP_KEY'], $_ENV['MAILIKA_SIEVE_ENABLED'], $_ENV['MAILIKA_IMAP_ADAPTER']);
    }

    public function testRegistryIndexesModulesCaseInsensitively(): void
    {
        $registry = new InternalModuleRegistry([
            new InternalModule('mail'),
            new InternalModule('filters', false),
        ]);

        self::assertTrue($registry->has('MAIL'));
        self::assertSame('mail', $registry->get('Mail')?->name());
        self::assertSame(['mail'], $registry->enabledNames());
        self::assertCount(2, $registry->all());
    }

    public function testRegistryRejectsDuplicateModules(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InternalModuleRegistry([
            new InternalModule('mail'),
            new InternalModule('MAIL'),
        ]);
    }

    public function testCoreModulesReflectOptionalRuntimeFeatures(): void
    {
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('o', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['MAILIKA_SIEVE_ENABLED'] = 'true';
        $_ENV['MAILIKA_IMAP_ADAPTER'] = 'fixture';

        $registry = CoreModules::fromConfig(Config::fromEnvironment(dirname(__DIR__, 2)));

        self::assertTrue($registry->get('filters')?->enabled());
        self::assertTrue($registry->get('fixture-mailbox')?->enabled());
    }
}

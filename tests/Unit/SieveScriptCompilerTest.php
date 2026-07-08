<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Filter\SieveRule;
use Mailika\Filter\SieveScriptCompiler;
use PHPUnit\Framework\TestCase;

final class SieveScriptCompilerTest extends TestCase
{
    public function testCompilerIncludesRequiredExtensionsAndEnabledRulesOnly(): void
    {
        $script = (new SieveScriptCompiler())->compile([
            new SieveRule('Invoices', true, 'from', 'contains', 'billing@example.com', 'fileinto', 'Archive'),
            new SieveRule('Disabled', false, 'subject', 'contains', 'ignored', 'discard'),
        ]);

        self::assertStringContainsString('require ["fileinto"];', $script);
        self::assertStringContainsString('# Invoices', $script);
        self::assertStringContainsString('if header :contains "From" "billing@example.com" {', $script);
        self::assertStringContainsString('fileinto "Archive";', $script);
        self::assertStringNotContainsString('Disabled', $script);
    }

    public function testCompilerEscapesSieveStrings(): void
    {
        $script = (new SieveScriptCompiler())->compile([
            new SieveRule('Quotes', true, 'subject', 'is', 'say "hello" \\ now', 'fileinto', 'Team "A"'),
        ]);

        self::assertStringContainsString('header :is "Subject" "say \\"hello\\" \\\\ now"', $script);
        self::assertStringContainsString('fileinto "Team \\"A\\"";', $script);
    }

    public function testCompilerAddsBodyExtensionForBodyMatches(): void
    {
        $script = (new SieveScriptCompiler())->compile([
            new SieveRule('Body', true, 'body', 'contains', 'unsubscribe', 'keep', null, false),
        ]);

        self::assertStringContainsString('require ["body"];', $script);
        self::assertStringContainsString('if body :contains "unsubscribe" {', $script);
        self::assertStringContainsString('keep;', $script);
        self::assertStringNotContainsString('stop;', $script);
    }

    public function testCompilerSupportsRedirectAndVacationActions(): void
    {
        $script = (new SieveScriptCompiler())->compile([
            new SieveRule('Escalate', true, 'subject', 'contains', 'urgent', 'redirect', 'ops@example.com'),
            new SieveRule(
                'Out of office',
                true,
                'to',
                'is',
                'me@example.com',
                'vacation',
                'I am away.',
                true,
                null,
                7,
                'Away from mail',
                ['noreply@example.com', 'alerts@example.com'],
                ['me@example.com', 'team@example.com'],
            ),
        ]);

        self::assertStringContainsString('require ["vacation"];', $script);
        self::assertStringContainsString('redirect "ops@example.com";', $script);
        self::assertStringContainsString(
            'if allof(header :is ["To", "Cc"] "me@example.com", '
                . 'not header :contains "From" ["noreply@example.com", "alerts@example.com"]) {',
            $script,
        );
        self::assertStringContainsString(
            'vacation :addresses ["me@example.com", "team@example.com"] '
                . ':days 7 :subject "Away from mail" "I am away.";',
            $script,
        );
    }
}

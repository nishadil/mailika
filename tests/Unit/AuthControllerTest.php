<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Audit\AuditLogger;
use Mailika\Auth\CredentialVault;
use Mailika\Auth\LoginPrefillStore;
use Mailika\Config\Config;
use Mailika\Controller\AuthController;
use Mailika\Http\Request;
use Mailika\Mail\MailAccountProfile;
use Mailika\Security\CsrfTokenManager;
use Mailika\Security\RateLimiter;
use Mailika\Support\View;
use Mailika\Tests\Fixtures\InMemoryAuditEventRepository;
use Mailika\Tests\Integration\FakeMailboxClient;
use PHPUnit\Framework\TestCase;

final class AuthControllerTest extends TestCase
{
    private string $cachePath;

    /**
     * @var list<string>
     */
    private array $envKeys = [
        'APP_ENV',
        'APP_KEY',
        'LOG_PATH',
        'MAILIKA_ALLOWED_IMAP_HOSTS',
        'MAILIKA_ALLOWED_SMTP_HOSTS',
        'MAILIKA_IMAP_VALIDATE_LOGIN',
        'MAILIKA_RATE_LIMIT_LOGIN_ATTEMPTS',
        'MAILIKA_REQUIRE_TLS',
    ];

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cachePath = sys_get_temp_dir() . '/mailika-auth-rate-' . bin2hex(random_bytes(4));

        $_ENV['APP_ENV'] = 'testing';
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('d', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . '/mailika-auth-' . bin2hex(random_bytes(4)) . '.log';
        $_ENV['MAILIKA_ALLOWED_IMAP_HOSTS'] = 'imap.example.com';
        $_ENV['MAILIKA_ALLOWED_SMTP_HOSTS'] = 'smtp.example.com';
        $_ENV['MAILIKA_IMAP_VALIDATE_LOGIN'] = 'true';
        $_ENV['MAILIKA_RATE_LIMIT_LOGIN_ATTEMPTS'] = '100';
        $_ENV['MAILIKA_REQUIRE_TLS'] = 'true';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        foreach ($this->envKeys as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        if (is_dir($this->cachePath)) {
            foreach (glob($this->cachePath . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($this->cachePath);
        }
    }

    public function testLoginRejectsDisallowedSmtpHost(): void
    {
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($csrf);

        $response = $controller->login($this->loginRequest($csrf->token(), [
            'smtp_host' => 'smtp.attacker.example',
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('This SMTP host is not allowed', $response->content());
        self::assertFalse((new CredentialVault($this->config()))->isAuthenticated());
    }

    public function testLoginRejectsPlainSmtpWhenTlsIsRequired(): void
    {
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($csrf);

        $response = $controller->login($this->loginRequest($csrf->token(), [
            'smtp_tls' => 'none',
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('TLS is required for SMTP', $response->content());
        self::assertFalse((new CredentialVault($this->config()))->isAuthenticated());
    }

    public function testLoginRejectsUnknownSmtpSecurityMode(): void
    {
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($csrf);

        $response = $controller->login($this->loginRequest($csrf->token(), [
            'smtp_tls' => 'implicit-but-not-real',
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Choose STARTTLS, SMTPS, or None', $response->content());
        self::assertFalse((new CredentialVault($this->config()))->isAuthenticated());
    }

    public function testLoginRejectsInvalidMailboxPorts(): void
    {
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($csrf);

        $response = $controller->login($this->loginRequest($csrf->token(), [
            'imap_port' => '0',
            'smtp_port' => '70000',
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Enter valid IMAP and SMTP ports.', $response->content());
        self::assertFalse((new CredentialVault($this->config()))->isAuthenticated());
    }

    public function testLoginStoresCredentialsOnlyAfterSmtpSecurityPolicyPasses(): void
    {
        $csrf = new CsrfTokenManager();
        $controller = $this->controller($csrf);

        $response = $controller->login($this->loginRequest($csrf->token(), [
            'smtp_tls' => 'SMTPS',
        ]));
        $credentials = (new CredentialVault($this->config()))->current();

        self::assertSame(302, $response->status());
        self::assertSame('/mailbox', $response->headers()['Location']);
        self::assertNotNull($credentials);
        self::assertSame('smtps', $credentials->smtpTls);
        self::assertSame('smtp.example.com', $credentials->smtpHost);
    }

    public function testLoginFormUsesAndConsumesNonSecretProfilePrefill(): void
    {
        $prefill = new LoginPrefillStore();
        $prefill->store(new MailAccountProfile(
            'Work',
            'work@example.com',
            'imap.work.example.com',
            993,
            true,
            'smtp.work.example.com',
            465,
            'smtps',
        ));

        $controller = $this->controller(prefill: $prefill);

        $html = $controller->showLogin(new Request('GET', '/login', [], [], [], [], []))->content();
        self::assertStringContainsString('value="work@example.com"', $html);
        self::assertStringContainsString('value="imap.work.example.com"', $html);
        self::assertStringContainsString('value="smtp.work.example.com"', $html);
        self::assertStringContainsString('value="465"', $html);
        self::assertStringNotContainsString('secret', $html);

        $secondHtml = $controller->showLogin(new Request('GET', '/login', [], [], [], [], []))->content();
        self::assertStringNotContainsString('imap.work.example.com', $secondHtml);
    }

    private function controller(?CsrfTokenManager $csrf = null, ?LoginPrefillStore $prefill = null): AuthController
    {
        $config = $this->config();

        return new AuthController(
            $config,
            new View($config->rootPath('templates'), $config->rootPath('public')),
            $csrf ?? new CsrfTokenManager(),
            new CredentialVault($config),
            new FakeMailboxClient(),
            new RateLimiter($this->cachePath, $config),
            new AuditLogger($config, new InMemoryAuditEventRepository()),
            $prefill ?? new LoginPrefillStore(),
        );
    }

    /**
     * @param array<string, string> $overrides
     */
    private function loginRequest(string $csrfToken, array $overrides = []): Request
    {
        return new Request('POST', '/login', [], array_merge([
            '_csrf' => $csrfToken,
            'email' => 'smoke@example.com',
            'password' => 'secret',
            'imap_host' => 'imap.example.com',
            'imap_port' => '993',
            'imap_tls' => '1',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_tls' => 'starttls',
        ], $overrides), [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'PHPUnit',
        ]);
    }

    private function config(): Config
    {
        return Config::fromEnvironment(dirname(__DIR__, 2));
    }
}

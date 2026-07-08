<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Config\Config;
use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Security\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $_ENV['APP_ENV'],
            $_ENV['MAILIKA_TRUSTED_PROXIES'],
            $_SERVER['APP_ENV'],
            $_SERVER['MAILIKA_TRUSTED_PROXIES'],
        );
    }

    public function testAppliesStrictBrowserSecurityHeaders(): void
    {
        $_ENV['APP_ENV'] = 'testing';

        $response = $this->headers()->apply(new Response(), $this->request());
        $headers = $response->headers();

        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame('DENY', $headers['X-Frame-Options']);
        self::assertSame('same-origin', $headers['Referrer-Policy']);
        self::assertSame('same-origin', $headers['Cross-Origin-Opener-Policy']);
        self::assertSame('same-origin', $headers['Cross-Origin-Resource-Policy']);
        self::assertSame('no-store', $headers['Cache-Control']);
        self::assertSame('no-cache', $headers['Pragma']);
        self::assertSame('0', $headers['Expires']);
        self::assertStringContainsString("default-src 'self'", $headers['Content-Security-Policy']);
        self::assertStringContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy']);
        self::assertStringContainsString("object-src 'none'", $headers['Content-Security-Policy']);
        self::assertStringContainsString('upgrade-insecure-requests', $headers['Content-Security-Policy']);
    }

    public function testAddsHstsOnlyForSecureProductionRequests(): void
    {
        $_ENV['APP_ENV'] = 'production';

        $secure = $this->headers()->apply(new Response(), $this->request(['HTTPS' => 'on']));
        $insecure = $this->headers()->apply(new Response(), $this->request(['HTTPS' => 'off']));

        self::assertSame(
            'max-age=31536000; includeSubDomains',
            $secure->headers()['Strict-Transport-Security'],
        );
        self::assertArrayNotHasKey('Strict-Transport-Security', $insecure->headers());
    }

    public function testAddsHstsForForwardedHttpsOnlyFromTrustedProxy(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['MAILIKA_TRUSTED_PROXIES'] = '10.0.0.0/8';

        $trusted = $this->headers()->apply(new Response(), $this->request([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));
        $untrusted = $this->headers()->apply(new Response(), $this->request([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));

        self::assertSame(
            'max-age=31536000; includeSubDomains',
            $trusted->headers()['Strict-Transport-Security'],
        );
        self::assertArrayNotHasKey('Strict-Transport-Security', $untrusted->headers());
    }

    public function testPreservesRouteSpecificContentSecurityPolicy(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $response = (new Response())->withHeader(
            'Content-Security-Policy',
            "default-src 'none'; sandbox",
        );

        $secured = $this->headers()->apply($response, $this->request());

        self::assertSame("default-src 'none'; sandbox", $secured->headers()['Content-Security-Policy']);
        self::assertSame('nosniff', $secured->headers()['X-Content-Type-Options']);
    }

    public function testPreservesRouteSpecificCacheControl(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $response = (new Response())->withHeader('Cache-Control', 'private, max-age=60');

        $secured = $this->headers()->apply($response, $this->request());

        self::assertSame('private, max-age=60', $secured->headers()['Cache-Control']);
        self::assertArrayNotHasKey('Pragma', $secured->headers());
        self::assertArrayNotHasKey('Expires', $secured->headers());
    }

    private function headers(): SecurityHeaders
    {
        return new SecurityHeaders(Config::fromEnvironment(dirname(__DIR__, 2)));
    }

    /**
     * @param array<string, mixed> $server
     */
    private function request(array $server = []): Request
    {
        return new Request('GET', '/', [], [], [], [], $server);
    }
}

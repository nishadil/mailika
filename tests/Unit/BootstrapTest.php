<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Bootstrap;
use Mailika\Config\Config;
use Mailika\Http\Request;
use Mailika\Http\Router;
use Mailika\Security\SecurityHeaders;
use Mailika\Support\View;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BootstrapTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['APP_ENV'], $_ENV['APP_DEBUG']);
    }

    public function testProductionExceptionPageDoesNotExposeExceptionDetails(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_DEBUG'] = 'false';
        $router = new Router();
        $router->get('/boom', static function (Request $request): never {
            throw new RuntimeException('secret database password leaked');
        });
        $root = dirname(__DIR__, 2);
        $config = Config::fromEnvironment($root);
        $app = new Bootstrap(
            $config,
            $router,
            new SecurityHeaders($config),
            new View($root . '/templates', $root . '/public'),
        );

        $response = $app->handle(new Request('GET', '/boom', [], [], [], [], []));
        $html = $this->send($response);

        self::assertSame(500, $response->status());
        self::assertStringContainsString('Something went wrong', $html);
        self::assertStringNotContainsString('secret database password leaked', $html);
        self::assertArrayHasKey('Content-Security-Policy', $response->headers());
    }

    private function send(\Mailika\Http\Response $response): string
    {
        ob_start();
        $response->send();
        return (string) ob_get_clean();
    }
}

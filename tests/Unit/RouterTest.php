<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use Mailika\Http\Request;
use Mailika\Http\Response;
use Mailika\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testDispatchesExactMethodAndPath(): void
    {
        $router = new Router();
        $router->get('/healthz', fn (Request $request): Response => Response::json(['status' => 'ok']));

        $response = $router->dispatch(new Request('GET', '/healthz', [], [], [], [], []));

        ob_start();
        $response->send();
        $content = (string) ob_get_clean();

        self::assertSame('{"status":"ok"}', $content);
    }

    public function testHeadFallsBackToGetRoute(): void
    {
        $router = new Router();
        $router->get('/login', fn (Request $request): Response => new Response('ok'));

        $response = $router->dispatch(new Request('HEAD', '/login', [], [], [], [], []));

        ob_start();
        $response->send();
        $content = (string) ob_get_clean();

        self::assertSame('ok', $content);
    }
}

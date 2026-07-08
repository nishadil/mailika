<?php

declare(strict_types=1);

namespace Mailika\Tests\Unit;

use InvalidArgumentException;
use Mailika\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testRejectsInvalidHeaderNames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Response('body', 200, ["Bad\r\nName" => 'value']);
    }

    public function testRejectsHeaderValuesWithControlLineBreaks(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Response())->withHeader('X-Test', "safe\r\nX-Injected: yes");
    }
}

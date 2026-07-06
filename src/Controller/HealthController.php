<?php

declare(strict_types=1);

namespace Mailika\Controller;

use Mailika\Config\Config;
use Mailika\Http\Request;
use Mailika\Http\Response;

final readonly class HealthController
{
    public function __construct(private Config $config)
    {
    }

    public function show(Request $request): Response
    {
        return Response::json([
            'status' => 'ok',
            'service' => 'mailika',
            'environment' => $this->config->string('app.env'),
            'php' => PHP_VERSION,
        ]);
    }
}

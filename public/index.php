<?php

declare(strict_types=1);

use Mailika\Bootstrap;
use Mailika\Http\Request;

define('MAILIKA_ROOT', dirname(__DIR__));

if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $publicRoot = __DIR__;
    $publicRootWithSeparator = $publicRoot . DIRECTORY_SEPARATOR;

    if (is_string($requestPath)) {
        $publicFile = realpath($publicRoot . '/' . ltrim($requestPath, '/'));
        $relativeFile = is_string($publicFile) && str_starts_with($publicFile, $publicRootWithSeparator)
            ? substr($publicFile, strlen($publicRootWithSeparator))
            : '';

        if (
            is_string($publicFile)
            && is_file($publicFile)
            && $relativeFile !== ''
            && !str_contains($relativeFile, DIRECTORY_SEPARATOR . '.')
            && !str_starts_with(basename($relativeFile), '.')
        ) {
            return false;
        }
    }
}

require MAILIKA_ROOT . '/vendor/autoload.php';

$request = Request::fromGlobals();
$app = Bootstrap::create(MAILIKA_ROOT, $request);
$app->handle($request)->send();

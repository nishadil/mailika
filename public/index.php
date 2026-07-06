<?php

declare(strict_types=1);

use Mailika\Bootstrap;
use Mailika\Http\Request;

define('MAILIKA_ROOT', dirname(__DIR__));

require MAILIKA_ROOT . '/vendor/autoload.php';

$app = Bootstrap::create(MAILIKA_ROOT);
$app->handle(Request::fromGlobals())->send();

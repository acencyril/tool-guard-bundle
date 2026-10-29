<?php

use Symfony\Component\HttpFoundation\Request;
use ToolGuardDemo\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$kernel = new Kernel('dev', false);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);

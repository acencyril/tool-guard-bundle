<?php

use Symfony\Component\HttpFoundation\Request;
use ToolGuardDemo\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// DEMO_PROFILER=1: debug mode with the Symfony profiler and its Tool Guard panel (/_profiler)
$profiler = '1' === (string) ($_SERVER['DEMO_PROFILER'] ?? getenv('DEMO_PROFILER'));
$kernel = new Kernel('dev', $profiler);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);

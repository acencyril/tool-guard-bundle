<?php

declare(strict_types=1);

use Acencyril\ToolGuardBundle\Controller\ApprovalsController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Import under your admin: tool_guard: { resource: '@ToolGuardBundle/config/routes.php', prefix: /admin/ai }
return static function (RoutingConfigurator $routes): void {
    $routes->add('tool_guard_approvals', '/approvals')->controller([ApprovalsController::class, 'index'])->methods(['GET']);
    $routes->add('tool_guard_decide', '/approvals/{id}/{decision}')->controller([ApprovalsController::class, 'decide'])
        ->methods(['POST'])->requirements(['id' => '[a-f0-9]{8}', 'decision' => 'approve|reject']);
};

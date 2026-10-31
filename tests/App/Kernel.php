<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests\App;

use Acencyril\ToolGuardBundle\ToolGuardBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/** A shop exposing guarded tools over MCP (HTTP, behind HTTP basic auth), and the approvals page. */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new SecurityBundle(), new TwigBundle(), new McpBundle(), new ToolGuardBundle()];
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2).'/var/test-app';
    }

    protected function configureContainer(ContainerConfigurator $c): void
    {
        $c->extension('framework', [
            'test' => true, 'secret' => 'test', 'http_method_override' => false,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => true, 'router' => ['utf8' => true],
        ]);
        $c->extension('security', [
            'password_hashers' => ['Symfony\Component\Security\Core\User\InMemoryUser' => 'plaintext'],
            'providers' => ['users' => ['memory' => ['users' => [
                'alice' => ['password' => 'pw', 'roles' => ['ROLE_USER']],
                'boss' => ['password' => 'pw', 'roles' => ['ROLE_ADMIN']],
            ]]]],
            'firewalls' => ['main' => ['http_basic' => null, 'stateless' => false]],
        ]);
        $c->extension('twig', ['default_path' => __DIR__]);
        $c->extension('mcp', ['servers' => ['shop' => ['http' => ['path' => '/mcp', 'allowed_hosts' => ['localhost']], 'registry' => '*']]]);
        $c->extension('tool_guard', [
            'approvals_dir' => '%kernel.project_dir%/approvals', 'journal' => '%kernel.project_dir%/journal.jsonl',
        ]);
        $s = $c->services()->defaults()->autowire()->autoconfigure();
        // the expected 403 of a test would otherwise be logged to the console as an "[error]"
        $s->set('logger', \Psr\Log\NullLogger::class);
        $s->set(McpShopTools::class);
        $s->set(OrderVoter::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'mcp');
        $routes->import('@ToolGuardBundle/config/routes.php')->prefix('/admin/ai');
    }
}

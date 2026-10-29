<?php

declare(strict_types=1);

namespace ToolGuardDemo;

use Acencyril\ToolGuardBundle\ToolGuardBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The demo shop: its tools over MCP at /mcp (HTTP basic: alice/alice is a customer, boss/boss
 * approves), the approvals page at /admin/ai/approvals. TOOL_GUARD_NTFY_TOPIC set: held calls
 * buzz the phone subscribed to https://ntfy.sh/<topic>.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new SecurityBundle(), new TwigBundle(), new McpBundle(), new ToolGuardBundle()];
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    protected function configureContainer(ContainerConfigurator $c): void
    {
        $c->extension('framework', [
            'secret' => 'demo-only-secret', 'http_method_override' => false, 'session' => true,
            'csrf_protection' => true, 'router' => ['utf8' => true], 'http_client' => true,
        ]);
        $c->extension('security', [
            'password_hashers' => ['Symfony\Component\Security\Core\User\InMemoryUser' => 'plaintext'],
            'providers' => ['users' => ['memory' => ['users' => [
                'alice' => ['password' => 'alice', 'roles' => ['ROLE_USER']],
                'boss' => ['password' => 'boss', 'roles' => ['ROLE_ADMIN']],
            ]]]],
            'firewalls' => ['main' => ['http_basic' => ['realm' => 'Tool Guard demo']]],
            'access_control' => [['path' => '^/', 'roles' => 'IS_AUTHENTICATED_FULLY']],
        ]);
        $c->extension('mcp', ['servers' => ['shop' => ['http' => ['path' => '/mcp', 'allowed_hosts' => ['localhost', '127.0.0.1']], 'registry' => ['tools' => ['ToolGuardDemo\\']]]]]);
        $topic = $_SERVER['TOOL_GUARD_NTFY_TOPIC'] ?? '';
        $c->extension('tool_guard', '' === $topic ? [] : ['ntfy' => [
            'topic_url' => 'https://ntfy.sh/'.$topic, 'approvals_url' => ($_SERVER['DEMO_URL'] ?? 'http://127.0.0.1:8000').'/admin/ai/approvals',
        ]]);
        $s = $c->services()->defaults()->autowire()->autoconfigure();
        $s->set(Shop::class)->arg('$file', '%kernel.project_dir%/var/shop.json');
        $s->set(ShopTools::class);
        $s->set(OrderVoter::class);
        $s->set(Command\AttackCommand::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'mcp');
        $routes->import('@ToolGuardBundle/config/routes.php')->prefix('/admin/ai');
        $routes->add('home', '/')->controller('Symfony\Bundle\FrameworkBundle\Controller\RedirectController')->defaults(['path' => '/admin/ai/approvals']);
    }
}

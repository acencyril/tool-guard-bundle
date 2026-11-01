<?php

declare(strict_types=1);

namespace ToolGuardDemo;

use Acencyril\ToolGuardBundle\ToolGuardBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The demo shop: its tools over MCP at /mcp (HTTP basic: alice/alice is a customer, boss/boss
 * approves), the approvals page at /admin/ai/approvals. TOOL_GUARD_NTFY_TOPIC set: held calls
 * buzz the phone subscribed to https://ntfy.sh/<topic>. DEMO_UNGUARDED=1: the same shop, the same
 * tools, without Tool Guard — to see what the hostile agent gets when the prompt is the only lock.
 * DEMO_PROFILER=1: the Symfony profiler, with the Tool Guard panel of every MCP request (/_profiler).
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new McpBundle();
        if (!self::unguarded()) {
            yield new ToolGuardBundle();
        }
        if ($this->profiler()) {
            yield new WebProfilerBundle();
        }
    }

    /** One compiled container per setting: changing an environment variable needs no cache clear. */
    public function getCacheDir(): string
    {
        return parent::getCacheDir().'-'.substr(md5(self::env('DEMO_UNGUARDED').'|'.self::env('TOOL_GUARD_NTFY_TOPIC').'|'.self::env('DEMO_URL').'|'.self::env('DEMO_PROFILER')), 0, 8);
    }

    private function profiler(): bool
    {
        return $this->debug && '1' === self::env('DEMO_PROFILER') && 'test' !== $this->environment && class_exists(WebProfilerBundle::class);
    }

    private static function unguarded(): bool
    {
        return '1' === self::env('DEMO_UNGUARDED');
    }

    /** The built-in PHP server does not copy the environment into $_SERVER: read it directly too. */
    private static function env(string $name): string
    {
        return (string) ($_SERVER[$name] ?? $_ENV[$name] ?? (getenv($name) ?: ''));
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    protected function configureContainer(ContainerConfigurator $c): void
    {
        $c->extension('framework', [
            'secret' => 'demo-only-secret', 'http_method_override' => false,
            'csrf_protection' => true, 'router' => ['utf8' => true], 'http_client' => true,
            'test' => 'test' === $this->environment,
            'session' => 'test' === $this->environment ? ['storage_factory_id' => 'session.storage.factory.mock_file'] : true,
        ] + ($this->profiler() ? ['profiler' => ['collect' => true]] : []));
        if ($this->profiler()) {
            $c->extension('web_profiler', ['toolbar' => true]);
        }
        $c->extension('security', [
            'password_hashers' => ['Symfony\Component\Security\Core\User\InMemoryUser' => 'plaintext'],
            'providers' => ['users' => ['memory' => ['users' => [
                'alice' => ['password' => 'alice', 'roles' => ['ROLE_USER']],
                'boss' => ['password' => 'boss', 'roles' => ['ROLE_ADMIN']],
            ]]]],
            'firewalls' => ['dev' => ['pattern' => '^/(_profiler|_wdt)', 'security' => false], 'main' => ['http_basic' => ['realm' => 'Tool Guard demo']]],
            'access_control' => [['path' => '^/', 'roles' => 'IS_AUTHENTICATED_FULLY']],
        ]);
        $c->extension('mcp', ['servers' => ['shop' => ['http' => ['path' => '/mcp', 'allowed_hosts' => ['localhost', '127.0.0.1']], 'registry' => ['tools' => ['ToolGuardDemo\\']]]]]);
        $topic = self::env('TOOL_GUARD_NTFY_TOPIC');
        // four eyes: the boss approves what alice's agent asked, nobody approves a call made for themselves.
        // agents: the shop's assistant (behind the MCP server "shop") may read orders, refund and draft the
        // newsletter, never export customers, even when the boss talks to it; marketing only emails
        self::unguarded() || $c->extension('tool_guard', ['four_eyes' => true, 'agents' => [
            'assistant' => ['tools' => ['order', 'refund', 'send_newsletter'], 'mcp_servers' => ['shop']],
            'marketing' => ['tools' => ['send_newsletter']],
        ]] + ('' === $topic ? [] : ['ntfy' => [
            'topic_url' => 'https://ntfy.sh/'.$topic, 'approvals_url' => (self::env('DEMO_URL') ?: 'http://127.0.0.1:8000').'/admin/ai/approvals',
        ]]));
        $s = $c->services()->defaults()->autowire()->autoconfigure();
        $s->set(Shop::class)->arg('$file', '%kernel.project_dir%/var/shop.json');
        $s->set(ShopTools::class);
        $s->set(OrderVoter::class);
        $s->set(Command\AttackCommand::class);
        self::unguarded() || $s->set(Command\RealModelAttackCommand::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'mcp');
        if ($this->profiler()) {
            $routes->import('@WebProfilerBundle/Resources/config/routing/wdt.php')->prefix('/_wdt');
            $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.php')->prefix('/_profiler');
        }
        if (self::unguarded()) {
            return;
        }
        $routes->import('@ToolGuardBundle/config/routes.php')->prefix('/admin/ai');
        $routes->add('home', '/')->controller('Symfony\Bundle\FrameworkBundle\Controller\RedirectController')->defaults(['path' => '/admin/ai/approvals']);
    }
}

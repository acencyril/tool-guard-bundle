<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle;

use Acencyril\ToolGuardBundle\Approval\ApprovalStore;
use Acencyril\ToolGuardBundle\Approval\Approver;
use Acencyril\ToolGuardBundle\Approval\FilesystemApprovalStore;
use Acencyril\ToolGuardBundle\Command\ApprovalsCommand;
use Acencyril\ToolGuardBundle\Command\RulesCommand;
use Acencyril\ToolGuardBundle\Controller\ApprovalsController;
use Acencyril\ToolGuardBundle\DependencyInjection\GuardPass;
use Acencyril\ToolGuardBundle\Event\ApprovalRequested;
use Acencyril\ToolGuardBundle\Event\ToolCallDecided;
use Acencyril\ToolGuardBundle\Integration\Agent\AgentScope;
use Acencyril\ToolGuardBundle\Integration\Agent\GuardToolCalls;
use Acencyril\ToolGuardBundle\Journal\Journal;
use Acencyril\ToolGuardBundle\Journal\JsonlJournal;
use Acencyril\ToolGuardBundle\Notify\NtfyNotifier;
use Acencyril\ToolGuardBundle\Notify\SymfonyNotifierListener;
use Acencyril\ToolGuardBundle\Profiler\ToolGuardDataCollector;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Notifier\NotifierInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

final class ToolGuardBundle extends AbstractBundle
{
    protected string $extensionAlias = 'tool_guard';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('approvals_dir')->defaultValue('%kernel.project_dir%/var/tool_guard/approvals')->end()
                ->scalarNode('journal')->defaultValue('%kernel.project_dir%/var/tool_guard/journal.jsonl')->end()
                ->scalarNode('approver_role')->defaultValue('ROLE_ADMIN')->info('Who may approve or reject on the approvals page')->end()
                ->scalarNode('cache')->defaultValue('cache.app')->info('PSR-6 pool counting #[RateLimit]')->end()
                ->arrayNode('ntfy')->info('A push to the approvers\' phones when a call waits (https://ntfy.sh)')->canBeEnabled()
                    ->children()
                        ->scalarNode('topic_url')->isRequired()->info('e.g. https://ntfy.sh/a-long-random-topic')->end()
                        ->scalarNode('approvals_url')->defaultNull()->info('The approvals page, absolute, opened from the notification')->end()
                        ->scalarNode('token')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('notifier')->info('Tell the approvers through Symfony Notifier: the channels the app already has (Slack, Teams, Telegram, email, SMS…)')->canBeEnabled()
                    ->children()
                        ->enumNode('importance')->values(['urgent', 'high', 'medium', 'low'])->defaultValue('high')->info('Picks the channels from framework.notifier.channel_policy when no channel is listed')->end()
                        ->arrayNode('channels')->scalarPrototype()->end()->info('e.g. ["chat/slack", "email"]; empty: the channel policy of the importance')->end()
                        ->scalarNode('approvals_url')->defaultNull()->info('The approvals page, absolute, linked from the message')->end()
                    ->end()
                ->end()
                ->integerNode('max_pending_per_user')->defaultValue(3)->min(1)->info('Requests one user may have waiting; more are refused')->end()
                ->integerNode('expire_after')->defaultValue(86400)->min(0)->info('Seconds a request may wait; older, it can no longer be approved (0: never expires)')->end()
                ->booleanNode('four_eyes')->defaultFalse()->info('Nobody approves a call made for themselves')->end()
                ->enumNode('mode')->values([Guard::ENFORCE, Guard::OBSERVE])->defaultValue(Guard::ENFORCE)->info('observe: nothing is blocked, the journal records what would have been')->end()
                ->scalarNode('lock_factory')->defaultValue('lock.factory')->info('Symfony Lock factory counting #[RateLimit] atomically (ignored if absent)')->end()
                ->arrayNode('agents')->info('Each agent\'s own role: the tools it may call. A call runs only if the user AND the agent may make it.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('tools')->scalarPrototype()->end()->info('Tool names as the model sees them; "*" for every tool')->end()
                            ->arrayNode('mcp_servers')->scalarPrototype()->end()->info('MCP servers of symfony/mcp-bundle whose calls come from this agent')->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static function (array $agents): bool {
                            $servers = array_merge(...array_values(array_map(static fn (array $a) => $a['mcp_servers'], $agents ?: [['mcp_servers' => []]])));

                            return \count($servers) !== \count(array_unique($servers));
                        })
                        ->thenInvalid('An MCP server belongs to one agent only.')
                    ->end()
                ->end()
                ->enumNode('unknown_agent')->values(['deny', 'allow'])->defaultValue('deny')->info('A call from no declared agent, once agents are declared')->end()
            ->end();
    }

    /** @param array{approvals_dir: string, journal: string, approver_role: string, cache: string, max_pending_per_user: int, expire_after: int, four_eyes: bool, mode: string, lock_factory: string, agents: array<string, array{tools: list<string>, mcp_servers: list<string>}>, unknown_agent: string, ntfy: array{enabled: bool, topic_url?: string, approvals_url?: ?string, token?: ?string}, notifier: array{enabled: bool, importance?: string, channels?: list<string>, approvals_url?: ?string}} $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $expire = 0 === $config['expire_after'] ? null : $config['expire_after'];
        $container->parameters()
            ->set('tool_guard.tool_classes', [])
            ->set('tool_guard.mcp_agents', array_merge([], ...array_values(array_map(
                static fn (string $name, array $a) => array_fill_keys($a['mcp_servers'], $name), array_keys($config['agents']), $config['agents'],
            ))))
            ->set('tool_guard.approvals_dir', $config['approvals_dir'])
            ->set('tool_guard.journal', $config['journal'])
            ->set('tool_guard.cache', $config['cache']);
        $s = $container->services();
        $s->set(FilesystemApprovalStore::class)->args([$config['approvals_dir']]);
        $s->alias(ApprovalStore::class, FilesystemApprovalStore::class);
        $s->set(JsonlJournal::class)->args([$config['journal']]);
        $s->alias(Journal::class, JsonlJournal::class);
        $s->set(CurrentUser::class)->args([service('security.token_storage')->nullOnInvalid()]);
        $s->set(Guard::class)->args([
            service(ApprovalStore::class), service(Journal::class),
            service('security.authorization_checker')->nullOnInvalid(), service($config['cache'])->nullOnInvalid(), service('event_dispatcher')->nullOnInvalid(), $config['max_pending_per_user'],
            service($config['lock_factory'])->nullOnInvalid(), $config['mode'], $expire,
            array_map(static fn (array $a) => ['tools' => $a['tools']], $config['agents']), 'allow' === $config['unknown_agent'],
        ]);
        $s->set(GuardedInvoker::class)->args([service(Guard::class), service(CurrentUser::class), service_locator([])])->public();
        $s->set(Approver::class)->args([service(ApprovalStore::class), service_locator([]), service(Journal::class), service('event_dispatcher')->nullOnInvalid(), $expire, $config['four_eyes']]);
        if ($builder->getParameter('kernel.debug')) {
            $s->set(ToolGuardDataCollector::class)
                ->tag('data_collector', ['template' => '@ToolGuard/Collector/tool_guard.html.twig', 'id' => 'tool_guard', 'priority' => 200])
                ->tag('kernel.event_listener', ['event' => ToolCallDecided::class, 'method' => 'onDecision'])
                ->tag('kernel.reset', ['method' => 'reset']);
        }
        $s->set(RulesCommand::class)->args([service(Guard::class), '%tool_guard.tool_classes%'])->tag('console.command');
        if ($config['ntfy']['enabled']) {
            $s->set(NtfyNotifier::class)
                ->args([service('http_client'), $config['ntfy']['topic_url'], $config['ntfy']['approvals_url'], $config['ntfy']['token'], service('logger')->nullOnInvalid()])
                ->tag('kernel.event_listener', ['event' => ApprovalRequested::class]);
        }
        if ($config['notifier']['enabled']) {
            if (!interface_exists(NotifierInterface::class)) {
                throw new \LogicException('tool_guard.notifier needs Symfony Notifier: composer require symfony/notifier');
            }
            $s->set(SymfonyNotifierListener::class)
                ->args([service('notifier'), $config['notifier']['importance'], $config['notifier']['channels'], $config['notifier']['approvals_url'], service('logger')->nullOnInvalid()])
                ->tag('kernel.event_listener', ['event' => ApprovalRequested::class]);
        }
        if (class_exists(ToolCallRequested::class)) {
            // registered for every agent by symfony/ai-bundle through its #[AsInputProcessor] / #[AsOutputProcessor]
            $s->set(AgentScope::class)->autoconfigure()->public()->tag('kernel.reset', ['method' => 'reset']);
            $s->set(GuardToolCalls::class)->args([service(Guard::class), service(CurrentUser::class), service(AgentScope::class)])
                ->tag('kernel.event_listener', ['event' => ToolCallRequested::class, 'priority' => 64]);
        }
        $s->set(ApprovalsCommand::class)->args([service(ApprovalStore::class), service(Approver::class), service(Journal::class)])->tag('console.command');
        $s->set(ApprovalsController::class)
            ->args([
                service(ApprovalStore::class), service(Approver::class), service(Journal::class), service(CurrentUser::class),
                service('twig')->nullOnInvalid(), service('security.authorization_checker')->nullOnInvalid(),
                service('security.csrf.token_manager')->nullOnInvalid(), service('router'), $config['approver_role'],
                $config['mode'], $expire,
            ])
            ->public()->tag('controller.service_arguments');
    }

    public function build(ContainerBuilder $container): void
    {
        // after symfony/mcp-bundle's pass, which declares each MCP server's builder
        $container->addCompilerPass(new GuardPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -16);
    }
}

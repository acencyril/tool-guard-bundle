<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle;

use Psr\Container\ContainerInterface;

/**
 * The guard for any other way of running a tool: your own execution layer, a workflow, a message
 * handler, code an agent wrote to chain several tools. Symfony AI toolboxes and MCP servers are
 * guarded on their own; anything else calls its tools through here, and the same attributes apply:
 *
 *     $invoker->call(ShopTools::class, 'refund', ['orderId' => 'A-1', 'amount' => 500]);
 *
 * Allowed: the method runs and its result is returned. Refused or held: nothing runs and
 * ToolCallBlocked is thrown (a held call is already waiting on the approvals page).
 */
final class GuardedInvoker
{
    public function __construct(
        private readonly Guard $guard,
        private readonly CurrentUser $user,
        private readonly ContainerInterface $tools,
    ) {
    }

    /**
     * @param class-string|object  $tool      a guarded tool service (its class, or the instance)
     * @param array<string, mixed> $arguments by parameter name, as a model would send them
     * @param string|null          $agent     the agent behind the call, checked against tool_guard.agents
     */
    public function call(string|object $tool, string $method, array $arguments, ?string $name = null, string $channel = Invocation::CODE, ?string $agent = null): mixed
    {
        $class = \is_object($tool) ? $tool::class : $tool;
        $service = \is_object($tool) ? $tool : ($this->tools->has($class) ? $this->tools->get($class) : null);
        if (null === $service) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a tool service with Tool Guard rules.', $class));
        }
        if ($this->guard->guards($class, $method)) {
            $decision = $this->guard->decide(new Invocation($channel, $name ?? $method, $class, $method, $arguments, $this->user->identifier(), $agent));
            if (!$decision->allowed()) {
                throw new ToolCallBlocked($decision);
            }
        }

        return Call::invoke($service, $method, $arguments);
    }
}

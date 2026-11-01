<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Integration\Mcp;

use Acencyril\ToolGuardBundle\CurrentUser;
use Acencyril\ToolGuardBundle\Guard;
use Acencyril\ToolGuardBundle\Invocation;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP servers: wraps the SDK's handler of each server (symfony/mcp-bundle), so every tools/call
 * passes the guard before the tool method runs. Refused or held: a tool result marked as an
 * error, with the reason, the method never called. The user is the one Symfony Security signed
 * in on the MCP route (HTTP transport behind a firewall). The agent is the one declared for this
 * MCP server in tool_guard.agents (agents.<name>.mcp_servers): one server, one role. The name a
 * client gives itself at initialize is chosen by the client, so it is never trusted for a decision.
 */
final class GuardedReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(
        private readonly ReferenceHandlerInterface $inner,
        private readonly Guard $guard,
        private readonly CurrentUser $user,
        private readonly ?string $agent = null,
    ) {
    }

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        [$class, $method] = match (true) {
            \is_array($reference->handler) && \is_string($reference->handler[0] ?? null) => [$reference->handler[0], (string) ($reference->handler[1] ?? '__invoke')],
            \is_string($reference->handler) && class_exists($reference->handler) => [$reference->handler, '__invoke'],
            default => [null, null],
        };
        if ($reference instanceof ToolReference && null !== $class && $this->guard->guards($class, $method)) {
            $args = array_filter($arguments, static fn ($k) => !\in_array($k, ['_session', '_request'], true), \ARRAY_FILTER_USE_KEY);
            $decision = $this->guard->decide(new Invocation(
                Invocation::MCP, $reference->tool->name, $class, $method, $args, $this->user->identifier(), $this->agent,
            ));
            if (!$decision->allowed()) {
                return CallToolResult::error([new TextContent($decision->messageForModel())]);
            }
        }

        return $this->inner->handle($reference, $arguments);
    }
}

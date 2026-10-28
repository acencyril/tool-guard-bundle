<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Integration\Agent;

use Acencyril\ToolGuardBundle\CurrentUser;
use Acencyril\ToolGuardBundle\Guard;
use Acencyril\ToolGuardBundle\Invocation;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\ToolResult;

/**
 * Symfony AI agents: every tool call the model asks for passes the guard before the toolbox runs
 * it. Refused: the model reads why, and the tool never runs. Held: the model reads that a human
 * has to approve, and the tool runs only if one does.
 */
final class GuardToolCalls
{
    public function __construct(private readonly Guard $guard, private readonly CurrentUser $user)
    {
    }

    public function __invoke(ToolCallRequested $event): void
    {
        $ref = $event->getDefinition()->getReference();
        if (!$this->guard->guards($ref->getClass(), $ref->getMethod())) {
            return;
        }
        $call = $event->getToolCall();
        $decision = $this->guard->decide(new Invocation(
            Invocation::AGENT, $call->getName(), $ref->getClass(), $ref->getMethod(), $call->getArguments(), $this->user->identifier(),
        ));
        if (!$decision->allowed()) {
            $event->setResult(new ToolResult($call, $decision->messageForModel()));
        }
    }
}

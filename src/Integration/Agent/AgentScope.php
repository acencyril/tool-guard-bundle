<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Integration\Agent;

use Symfony\AI\Agent\AgentAwareInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Attribute\AsOutputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Agent\Output;
use Symfony\AI\Agent\OutputProcessorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which Symfony AI agent is running, so its tool calls are checked against its own role. The
 * toolbox's ToolCallRequested event does not say which agent asked; every agent tells its input
 * and output processors who it is (AgentAwareInterface), around the tool calls of its run. So
 * this processor notes the agent's name (AgentInterface::getName(), the agent's name in the
 * ai-bundle configuration) when a run starts and forgets it when it ends; a sub-agent called
 * from a tool stacks on top and gives its parent back.
 *
 * With symfony/ai-bundle it is registered for every agent through the attributes below; an
 * agent built by hand takes it in its inputProcessors and outputProcessors; a loop of your own
 * that runs a toolbox marks itself with enter('name') / leave().
 */
#[AsInputProcessor(priority: 1024)]
#[AsOutputProcessor(priority: -1024)]
final class AgentScope implements InputProcessorInterface, OutputProcessorInterface, AgentAwareInterface, ResetInterface
{
    /** @var list<string> */
    private array $stack = [];
    private ?string $next = null;

    public function setAgent(AgentInterface $agent): void
    {
        $this->next = $agent->getName();
    }

    public function processInput(Input $input): void
    {
        if (null !== $this->next) {
            $this->enter($this->next);
        }
    }

    public function processOutput(Output $output): void
    {
        $this->leave();
    }

    /** The tool calls from now on come from this agent, until leave(). */
    public function enter(string $agent): void
    {
        $this->stack[] = $agent;
    }

    public function leave(): void
    {
        array_pop($this->stack);
    }

    /** The agent whose run is in progress, or null outside any agent. */
    public function current(): ?string
    {
        return $this->stack[\count($this->stack) - 1] ?? null;
    }

    public function reset(): void
    {
        $this->stack = [];
        $this->next = null;
    }
}

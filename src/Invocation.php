<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle;

/** One tool call an AI asked for: where it came from, which method, with what, for whom, through which agent. */
final class Invocation
{
    public const AGENT = 'agent';
    public const MCP = 'mcp';
    public const CODE = 'code';
    /**
     * A person (a back-office form) or the app's own deterministic code (a workflow, a queue), never
     * an AI: every attribute rule applies and it is journaled, but the agents' roles do not, and only
     * the emergency stop (Stop::ALL) halts it. Never use it for code an agent wrote or asked for.
     */
    public const HUMAN = 'human';

    /**
     * @param class-string         $class
     * @param array<string, mixed> $arguments as the model sent them
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $tool,
        public readonly string $class,
        public readonly string $method,
        public readonly array $arguments,
        public readonly ?string $user = null,
        public readonly ?string $agent = null,
    ) {
    }

    /** An AI asked for it (an agent, an MCP client, code on an agent's behalf), as opposed to a person or the app's own code. */
    public function byAi(): bool
    {
        return self::HUMAN !== $this->channel;
    }
}

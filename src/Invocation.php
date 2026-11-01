<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle;

/** One tool call an AI asked for: where it came from, which method, with what, for whom. */
final class Invocation
{
    public const AGENT = 'agent';
    public const MCP = 'mcp';
    public const CODE = 'code';

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
    ) {
    }
}

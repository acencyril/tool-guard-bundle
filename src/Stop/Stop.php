<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Stop;

/**
 * A stop a human pressed, until someone resumes it. While it holds, every matching call is refused
 * before any other rule, and a held call in its scope cannot be approved.
 *
 * Stopping the AI never stops the business: the scopes AGENTS (every AI caller), AGENT (one agent of
 * tool_guard.agents) and TOOL (one tool, as an AI calls it) halt the calls an AI makes (Symfony AI
 * agents, MCP clients, GuardedInvoker on behalf of an agent), never the same tool called by a person
 * or by the app's own code (Invocation::HUMAN): a back-office form still refunds, with its rules.
 * Only ALL, the emergency stop, halts every caller.
 */
final class Stop
{
    /** Everything: every tool, every caller, people and the app's own code included. The emergency stop. */
    public const ALL = 'all';
    /** Every AI caller (agent, MCP client), every tool. The recommended stop. */
    public const AGENTS = 'agents';
    public const TOOL = 'tool';
    public const AGENT = 'agent';

    public function __construct(
        public readonly string $scope,
        public readonly ?string $target,
        public readonly ?string $by,
        public readonly \DateTimeImmutable $at,
        public readonly ?string $reason = null,
    ) {
        if (!\in_array($scope, [self::ALL, self::AGENTS, self::TOOL, self::AGENT], true)) {
            throw new \InvalidArgumentException(sprintf('A stop is for "%s", "%s", "%s" or "%s".', self::ALL, self::AGENTS, self::TOOL, self::AGENT));
        }
        if (self::named($scope) ? (null === $target || '' === trim($target)) : null !== $target) {
            throw new \InvalidArgumentException(self::named($scope) ? sprintf('A stop of one %s names it.', $scope) : sprintf('A stop of %s names no tool or agent.', self::ALL === $scope ? 'everything' : 'all agents'));
        }
    }

    /** Whether the scope names one tool or one agent. */
    public static function named(string $scope): bool
    {
        return self::TOOL === $scope || self::AGENT === $scope;
    }

    public static function agents(?string $by, ?string $reason = null, ?\DateTimeImmutable $at = null): self
    {
        return new self(self::AGENTS, null, $by, $at ?? new \DateTimeImmutable(), self::clean($reason));
    }

    public static function all(?string $by, ?string $reason = null, ?\DateTimeImmutable $at = null): self
    {
        return new self(self::ALL, null, $by, $at ?? new \DateTimeImmutable(), self::clean($reason));
    }

    public static function tool(string $tool, ?string $by, ?string $reason = null, ?\DateTimeImmutable $at = null): self
    {
        return new self(self::TOOL, $tool, $by, $at ?? new \DateTimeImmutable(), self::clean($reason));
    }

    public static function agent(string $agent, ?string $by, ?string $reason = null, ?\DateTimeImmutable $at = null): self
    {
        return new self(self::AGENT, $agent, $by, $at ?? new \DateTimeImmutable(), self::clean($reason));
    }

    /** One key per scope: stopping the same tool twice replaces the first stop. */
    public function key(): string
    {
        return self::keyFor($this->scope, $this->target);
    }

    /** @internal */
    public static function keyFor(string $scope, ?string $target): string
    {
        return self::named($scope) ? $scope.':'.$target : $scope;
    }

    /** $byAi: false for a call a person or the app's own code makes (Invocation::HUMAN), which only ALL stops. */
    public function matches(string $tool, ?string $agent, bool $byAi = true): bool
    {
        if (self::ALL === $this->scope) {
            return true;
        }
        if (!$byAi) {
            return false;
        }

        return match ($this->scope) {
            self::AGENTS => true,
            self::TOOL => $tool === $this->target,
            self::AGENT => null !== $agent && $agent === $this->target,
        };
    }

    /**
     * The stop that halts this call, if any; the broadest first (everything, every agent, the
     * agent, then the tool), so the reason given is the one a human has to lift last.
     *
     * @param list<self> $stops
     */
    public static function matching(array $stops, string $tool, ?string $agent, bool $byAi = true): ?self
    {
        $order = [self::ALL => 0, self::AGENTS => 1, self::AGENT => 2, self::TOOL => 3];
        usort($stops, static fn (self $a, self $b) => $order[$a->scope] <=> $order[$b->scope]);
        foreach ($stops as $s) {
            if ($s->matches($tool, $agent, $byAi)) {
                return $s;
            }
        }

        return null;
    }

    /** What it stops, in words: "everything", "all agents", "tool "refund"", "agent "assistant"". */
    public function what(): string
    {
        return match ($this->scope) {
            self::ALL => 'everything',
            self::AGENTS => 'all agents',
            default => sprintf('%s "%s"', $this->scope, $this->target),
        };
    }

    /** The reason a refused call gives: who stopped it, when, and why. */
    public function describe(): string
    {
        return sprintf('%s stopped by %s at %s%s', $this->what(), $this->by ?? 'anonymous', $this->at->format('Y-m-d H:i P'), null === $this->reason ? '' : ': '.$this->reason);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['scope' => $this->scope, 'target' => $this->target, 'by' => $this->by, 'at' => $this->at->format(\DATE_ATOM), 'reason' => $this->reason];
    }

    /** @param array<string, mixed> $d */
    public static function fromArray(array $d): self
    {
        return new self((string) $d['scope'], $d['target'] ?? null, $d['by'] ?? null, new \DateTimeImmutable((string) $d['at']), $d['reason'] ?? null);
    }

    private static function clean(?string $reason): ?string
    {
        $reason = null === $reason ? '' : trim((string) preg_replace('/\s+/', ' ', $reason));

        return '' === $reason ? null : mb_substr($reason, 0, 500);
    }
}

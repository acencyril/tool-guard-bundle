<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Profiler;

use Acencyril\ToolGuardBundle\Event\ToolCallDecided;
use Acencyril\ToolGuardBundle\Stop\Stop;
use Acencyril\ToolGuardBundle\Stop\StopStore;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** The "Tool Guard" panel of the Symfony profiler: what is stopped, every tool call the AI asked for in this request, and what the guard decided. */
final class ToolGuardDataCollector extends DataCollector
{
    /** @var list<array<string, mixed>> */
    private array $calls = [];

    public function __construct(private readonly ?StopStore $stops = null)
    {
    }

    public function onDecision(ToolCallDecided $e): void
    {
        $this->calls[] = [
            'tool' => $e->call->tool, 'channel' => $e->call->channel, 'user' => $e->call->user, 'agent' => $e->call->agent,
            'arguments' => $e->call->arguments, 'verdict' => $e->decision->verdict,
            'reason' => $e->wouldHave?->reason ?? $e->decision->reason, 'approval' => $e->decision->approvalId,
            'would' => $e->wouldHave?->verdict,
        ];
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        try {
            $stops = array_map(static fn (Stop $s) => $s->describe(), $this->stops?->all() ?? []);
        } catch (\RuntimeException) {
            $stops = ['the stop state cannot be read: every call is refused'];
        }
        $this->data = ['calls' => $this->calls, 'stops' => $stops];
    }

    public function reset(): void
    {
        $this->data = [];
        $this->calls = [];
    }

    /** @return list<array<string, mixed>> */
    public function getCalls(): array
    {
        return $this->data['calls'] ?? [];
    }

    /** @return list<string> what was stopped when the request ended, in words */
    public function getStops(): array
    {
        return $this->data['stops'] ?? [];
    }

    public function count(string $verdict): int
    {
        return \count(array_filter($this->getCalls(), static fn (array $c) => $verdict === ($c['would'] ?? $c['verdict'])));
    }

    public function getName(): string
    {
        return 'tool_guard';
    }
}

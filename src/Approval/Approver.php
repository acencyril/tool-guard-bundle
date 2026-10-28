<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Approval;

use Acencyril\ToolGuardBundle\Event\ApprovalDecided;
use Acencyril\ToolGuardBundle\Journal\Journal;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A human's decision on a held call. Approve: the tool runs now, once, with the arguments that
 * were asked (shown to the approver), and its result is kept. Reject: nothing runs. Either way,
 * the journal and an ApprovalDecided event. A decided request cannot be decided again.
 */
final class Approver
{
    public function __construct(
        private readonly ApprovalStore $store,
        private readonly ContainerInterface $tools,
        private readonly Journal $journal,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
    }

    public function approve(string $id, ?string $by): Approval
    {
        $a = $this->pending($id);
        try {
            $result = $this->run($a);
            $a->status = Approval::APPROVED;
            $a->result = self::text($result);
        } catch (\Throwable $e) {
            $a->status = Approval::FAILED;
            $a->result = 'Error: '.$e->getMessage();
        }

        return $this->close($a, $by);
    }

    public function reject(string $id, ?string $by): Approval
    {
        $a = $this->pending($id);
        $a->status = Approval::REJECTED;

        return $this->close($a, $by);
    }

    private function pending(string $id): Approval
    {
        $a = $this->store->get($id) ?? throw new \InvalidArgumentException(sprintf('No request "%s".', $id));
        if (Approval::PENDING !== $a->status) {
            throw new \LogicException(sprintf('Request "%s" is already %s.', $id, $a->status));
        }

        return $a;
    }

    private function close(Approval $a, ?string $by): Approval
    {
        $a->decidedBy = $by;
        $a->decidedAt = new \DateTimeImmutable();
        $this->store->save($a);
        $this->journal->record(['event' => 'decision', 'approval' => $a->id, 'tool' => $a->tool, 'status' => $a->status, 'by' => $by, 'requested_by' => $a->requestedBy]);
        $this->events?->dispatch(new ApprovalDecided($a));

        return $a;
    }

    private function run(Approval $a): mixed
    {
        if (!$this->tools->has($a->class)) {
            throw new \RuntimeException(sprintf('Tool service "%s" not found.', $a->class));
        }
        $service = $this->tools->get($a->class);
        $m = new \ReflectionMethod($service, $a->method);
        $args = [];
        foreach ($m->getParameters() as $p) {
            if (\array_key_exists($p->getName(), $a->arguments)) {
                $args[] = $a->arguments[$p->getName()];
            } elseif ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
            } elseif ($p->allowsNull()) {
                $args[] = null;
            } else {
                throw new \RuntimeException(sprintf('Argument "%s" missing.', $p->getName()));
            }
        }

        return $m->invokeArgs($service, $args);
    }

    private static function text(mixed $result): string
    {
        return match (true) {
            null === $result => 'done',
            \is_scalar($result) || $result instanceof \Stringable => (string) $result,
            default => (string) json_encode($result, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR),
        };
    }
}

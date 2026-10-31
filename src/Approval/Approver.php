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
 * the journal and an ApprovalDecided event.
 *
 * Exactly one decision per request: it is claimed in the store before the tool runs, so two
 * approvers clicking at once run it once. A request older than $expireAfter seconds can no longer
 * be approved (it is closed as expired). With $fourEyes, nobody approves a call made for themselves.
 */
final class Approver
{
    public function __construct(
        private readonly ApprovalStore $store,
        private readonly ContainerInterface $tools,
        private readonly Journal $journal,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?int $expireAfter = null,
        private readonly bool $fourEyes = false,
    ) {
    }

    public function approve(string $id, ?string $by): Approval
    {
        $a = $this->pending($id);
        if ($this->fourEyes && null !== $a->requestedBy && $by === $a->requestedBy) {
            throw new \LogicException(sprintf('Request "%s" was made for %s: another approver must decide it.', $id, $by));
        }
        $this->claim($a);
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
        $this->claim($a);
        $a->status = Approval::REJECTED;

        return $this->close($a, $by);
    }

    private function pending(string $id): Approval
    {
        $a = $this->store->get($id) ?? throw new \InvalidArgumentException(sprintf('No request "%s".', $id));
        if (Approval::PENDING !== $a->status) {
            throw new \LogicException(sprintf('Request "%s" is already %s.', $id, $a->status));
        }
        if ($a->expired($this->expireAfter)) {
            if ($this->store->claim($a->id)) {
                $a->status = Approval::EXPIRED;
                $this->close($a, null);
            }
            throw new \LogicException(sprintf('Request "%s" expired: it waited more than %s.', $id, self::duration((int) $this->expireAfter)));
        }

        return $a;
    }

    private function claim(Approval $a): void
    {
        if (!$this->store->claim($a->id)) {
            throw new \LogicException(sprintf('Request "%s" is already being decided.', $a->id));
        }
    }

    private static function duration(int $s): string
    {
        return 0 === $s % 3600 ? ($s / 3600).' hour(s)' : (0 === $s % 60 ? ($s / 60).' minute(s)' : $s.' seconds');
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

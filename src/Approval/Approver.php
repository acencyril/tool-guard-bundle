<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Approval;

use Acencyril\ToolGuardBundle\Call;
use Acencyril\ToolGuardBundle\Event\ApprovalDecided;
use Acencyril\ToolGuardBundle\Invocation;
use Acencyril\ToolGuardBundle\Journal\Journal;
use Acencyril\ToolGuardBundle\Stop\Stop;
use Acencyril\ToolGuardBundle\Stop\StopStore;
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
 * While a stop holds its scope (see Stop: a person's request is only held back by the emergency stop), a request cannot be approved: it stays
 * pending, untouched, and can be approved once someone resumes. Rejecting stays possible.
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
        private readonly ?StopStore $stops = null,
    ) {
    }

    public function approve(string $id, ?string $by): Approval
    {
        $a = $this->pending($id);
        if ($this->fourEyes && null !== $a->requestedBy && $by === $a->requestedBy) {
            throw new \LogicException(sprintf('Request "%s" was made for %s: another approver must decide it.', $id, $by));
        }
        $this->notStopped($a);
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

    /** Nothing runs while stopped, not even what a human approves: the request waits for the resume. */
    private function notStopped(Approval $a): void
    {
        if (null === $this->stops) {
            return;
        }
        try {
            $stop = Stop::matching($this->stops->all(), $a->tool, $a->agent, Invocation::HUMAN !== $a->channel);
        } catch (\Throwable) {
            throw new \LogicException(sprintf('Request "%s" cannot be approved: the stop state cannot be read. It still waits.', $a->id));
        }
        if (null !== $stop) {
            throw new \LogicException(sprintf('Request "%s" cannot be approved while %s. Resume first; the request still waits.', $a->id, $stop->describe()));
        }
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

        return Call::invoke($this->tools->get($a->class), $a->method, $a->arguments);
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

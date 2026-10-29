<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle;

use Acencyril\ToolGuardBundle\Approval\Approval;
use Acencyril\ToolGuardBundle\Approval\ApprovalStore;
use Acencyril\ToolGuardBundle\Attribute\Granted;
use Acencyril\ToolGuardBundle\Attribute\Limit;
use Acencyril\ToolGuardBundle\Attribute\RateLimit;
use Acencyril\ToolGuardBundle\Attribute\RequiresApproval;
use Acencyril\ToolGuardBundle\Event\ApprovalRequested;
use Acencyril\ToolGuardBundle\Event\ToolCallRefused;
use Acencyril\ToolGuardBundle\Journal\Journal;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Decides each tool call an AI asks for, from the attributes on the tool's method and class,
 * in this order: #[Granted] (refuse), #[RateLimit] (refuse), #[Limit] (refuse or hold),
 * #[RequiresApproval] (hold). Everything is decided in code: whatever the model was talked
 * into, a refused call does not run. Each decision goes to the journal.
 *
 * The text a human approves on is the policy's reason and the raw arguments, never words the
 * model wrote: the model cannot write its own justification onto the approver's screen.
 *
 * Fails closed: an attribute that cannot be checked (no security, an argument missing) refuses.
 */
final class Guard
{
    /** @var array<string, array{granted: list<Granted>, limits: list<Limit>, rate: ?RateLimit, approval: ?RequiresApproval}> */
    private array $rules = [];

    public function __construct(
        private readonly ApprovalStore $approvals,
        private readonly Journal $journal,
        private readonly ?AuthorizationCheckerInterface $auth = null,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly int $maxPendingPerUser = 3,
    ) {
    }

    public function decide(Invocation $call): Decision
    {
        $decision = $this->evaluate($call);
        $this->journal->record([
            'event' => 'call', 'channel' => $call->channel, 'tool' => $call->tool, 'user' => $call->user,
            'arguments' => $call->arguments, 'verdict' => $decision->verdict, 'reason' => $decision->reason, 'approval' => $decision->approvalId,
        ]);
        if (Decision::DENY === $decision->verdict) {
            $this->events?->dispatch(new ToolCallRefused($call, (string) $decision->reason));
        }

        return $decision;
    }

    /** Whether the tool has any rule (the integrations skip the journal for unguarded tools). */
    public function guards(string $class, string $method): bool
    {
        $r = $this->rules($class, $method);

        return [] !== $r['granted'] || [] !== $r['limits'] || null !== $r['rate'] || null !== $r['approval'];
    }

    private function evaluate(Invocation $call): Decision
    {
        $r = $this->rules($call->class, $call->method);

        foreach ($r['granted'] as $g) {
            if (null === $this->auth) {
                return Decision::deny(sprintf('permission "%s" cannot be checked (no security)', $g->attribute));
            }
            if (null !== $g->subject && !\array_key_exists($g->subject, $call->arguments)) {
                return Decision::deny(sprintf('argument "%s" missing for the permission check', $g->subject));
            }
            try {
                $ok = $this->auth->isGranted($g->attribute, null === $g->subject ? null : $call->arguments[$g->subject]);
            } catch (\Throwable) {
                $ok = false; // no user signed in, for instance
            }
            if (!$ok) {
                return Decision::deny($g->message ?? sprintf('permission "%s" not granted%s', $g->attribute, null === $g->subject ? '' : sprintf(' for %s "%s"', $g->subject, self::scalar($call->arguments[$g->subject]))));
            }
        }

        if (null !== $r['rate'] && !$this->withinRate($call, $r['rate'])) {
            return Decision::deny(sprintf('more than %d calls in %s', $r['rate']->limit, self::duration($r['rate']->seconds)));
        }

        $hold = null;
        foreach ($r['limits'] as $l) {
            $v = $call->arguments[$l->argument] ?? null;
            if (!is_numeric($v) || !is_finite((float) $v)) {
                return Decision::deny(sprintf('"%s" must be a number', $l->argument));
            }
            if (null !== $l->min && (float) $v < $l->min) {
                return Decision::deny(sprintf('%s %s is below the minimum of %s', $l->argument, self::scalar($v), self::scalar($l->min)));
            }
            if ((float) $v > $l->max) {
                $why = sprintf('%s %s is above the limit of %s', $l->argument, self::scalar($v), self::scalar($l->max));
                if (Limit::DENY === $l->above) {
                    return Decision::deny($why);
                }
                $hold ??= $why;
            }
        }
        if (null === $hold && null !== $r['approval']) {
            $hold = $r['approval']->reason ?? sprintf('every "%s" is approved by a human', $call->tool);
        }
        if (null !== $hold) {
            // an approver is not worn down by a flood of requests: past a few waiting, refused
            $mine = array_filter($this->approvals->all(Approval::PENDING, 500), static fn (Approval $a) => $a->requestedBy === $call->user);
            if (\count($mine) >= $this->maxPendingPerUser) {
                return Decision::deny(sprintf('%d requests already wait for a human', \count($mine)));
            }
            $approval = Approval::for($call, $hold);
            $this->approvals->save($approval);
            $this->events?->dispatch(new ApprovalRequested($approval));

            return Decision::pending($hold, $approval->id);
        }

        return Decision::allow();
    }

    private function withinRate(Invocation $call, RateLimit $rate): bool
    {
        if (null === $this->cache) {
            return false; // a rate that cannot be counted is not a rate
        }
        $who = RateLimit::PER_TOOL === $rate->per ? '*' : ($call->user ?? 'anonymous');
        $slot = intdiv(time(), $rate->seconds);
        $item = $this->cache->getItem('tool_guard.'.hash('xxh128', $call->class.'::'.$call->method.'|'.$who.'|'.$slot));
        $n = ($item->isHit() ? (int) $item->get() : 0) + 1;
        $item->set($n);
        $item->expiresAfter($rate->seconds + 60);
        $this->cache->save($item);

        return $n <= $rate->limit;
    }

    /** @return array{granted: list<Granted>, limits: list<Limit>, rate: ?RateLimit, approval: ?RequiresApproval} */
    private function rules(string $class, string $method): array
    {
        $key = $class.'::'.$method;
        if (isset($this->rules[$key])) {
            return $this->rules[$key];
        }
        $empty = ['granted' => [], 'limits' => [], 'rate' => null, 'approval' => null];
        if (!method_exists($class, $method)) {
            return $this->rules[$key] = $empty;
        }
        $c = new \ReflectionClass($class);
        $m = new \ReflectionMethod($class, $method);
        $all = static fn (string $a) => array_map(static fn ($x) => $x->newInstance(), [...$c->getAttributes($a), ...$m->getAttributes($a)]);
        $one = static function (string $a) use ($c, $m) {
            $x = $m->getAttributes($a)[0] ?? $c->getAttributes($a)[0] ?? null;

            return $x?->newInstance();
        };

        return $this->rules[$key] = [
            'granted' => $all(Granted::class),
            'limits' => array_map(static fn ($x) => $x->newInstance(), $m->getAttributes(Limit::class)),
            'rate' => $one(RateLimit::class),
            'approval' => $one(RequiresApproval::class),
        ];
    }

    private static function scalar(mixed $v): string
    {
        return \is_scalar($v) ? (string) $v : get_debug_type($v);
    }

    private static function duration(int $s): string
    {
        return match (true) {
            0 === $s % 86400 => ($s / 86400).' day(s)',
            0 === $s % 3600 => ($s / 3600).' hour(s)',
            0 === $s % 60 => ($s / 60).' minute(s)',
            default => $s.' seconds',
        };
    }
}

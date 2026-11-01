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
use Acencyril\ToolGuardBundle\Event\ToolCallDecided;
use Acencyril\ToolGuardBundle\Event\ToolCallRefused;
use Acencyril\ToolGuardBundle\Journal\Journal;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
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
 *
 * Agents have their own role (`agents`): a call runs only if the user AND the agent behind it are
 * allowed — an agent never gets every right of the person talking to it. Checked right after
 * #[Granted], on every tool, guarded or not, as soon as one agent is declared.
 *
 * Observe mode (mode: observe) applies nothing: every call runs, and the journal records what
 * enforce mode would have done ("would": deny or pending). Turn it on in production first, read
 * the journal, then enforce.
 */
final class Guard
{
    public const ENFORCE = 'enforce';
    public const OBSERVE = 'observe';

    /** @var array<string, array{granted: list<Granted>, limits: list<Limit>, rate: ?RateLimit, approval: ?RequiresApproval}> */
    private array $rules = [];

    public function __construct(
        private readonly ApprovalStore $approvals,
        private readonly Journal $journal,
        private readonly ?AuthorizationCheckerInterface $auth = null,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly int $maxPendingPerUser = 3,
        private readonly ?LockFactory $locks = null,
        private readonly string $mode = self::ENFORCE,
        private readonly ?int $expireAfter = null,
        /** @var array<string, array{tools: list<string>}> */
        private readonly array $agents = [],
        private readonly bool $allowUnknownAgent = false,
    ) {
        if (!\in_array($mode, [self::ENFORCE, self::OBSERVE], true)) {
            throw new \InvalidArgumentException(sprintf('Tool Guard mode must be "%s" or "%s".', self::ENFORCE, self::OBSERVE));
        }
    }

    public function decide(Invocation $call): Decision
    {
        $decision = $this->evaluate($call);
        $would = null;
        if (self::OBSERVE === $this->mode && !$decision->allowed()) {
            [$would, $decision] = [$decision, Decision::allow()];
        } elseif (Decision::PENDING === $decision->verdict) {
            $decision = $this->hold($call, (string) $decision->reason);
        }
        $entry = [
            'event' => 'call', 'channel' => $call->channel, 'tool' => $call->tool, 'user' => $call->user,
            'arguments' => $call->arguments, 'verdict' => $decision->verdict, 'reason' => $would?->reason ?? $decision->reason, 'approval' => $decision->approvalId,
        ];
        if (null !== $would) {
            $entry['would'] = $would->verdict;
        }
        if (null !== $call->agent) {
            $entry['agent'] = $call->agent;
        }
        $this->journal->record($entry);
        if (Decision::DENY === $decision->verdict) {
            $this->events?->dispatch(new ToolCallRefused($call, (string) $decision->reason));
        }
        $this->events?->dispatch(new ToolCallDecided($call, $decision, $would));

        return $decision;
    }

    /**
     * The rules of a tool, in words, in the order they are checked (debug:tool-guard).
     *
     * @return list<string>
     */
    public function describe(string $class, string $method): array
    {
        $r = $this->rules($class, $method);
        $out = [];
        foreach ($r['granted'] as $g) {
            $out[] = sprintf('#[Granted] %s%s', $g->attribute, null === $g->subject ? '' : ' on '.$g->subject);
        }
        if (null !== $r['rate']) {
            $out[] = sprintf('#[RateLimit] %d per %s, per %s', $r['rate']->limit, self::duration($r['rate']->seconds), $r['rate']->per);
        }
        foreach ($r['limits'] as $l) {
            $out[] = sprintf('#[Limit] %s%s ≤ %s, above: %s', null === $l->min ? '' : self::scalar($l->min).' ≤ ', $l->argument, self::scalar($l->max), Limit::DENY === $l->above ? 'refused' : 'held for a human');
        }
        if (null !== $r['approval']) {
            $out[] = '#[RequiresApproval]'.(null === $r['approval']->reason ? '' : ' '.$r['approval']->reason);
        }

        return $out;
    }

    /** Whether the tool has any rule (the integrations skip the journal for unguarded tools). With agents declared, every tool is checked. */
    public function guards(string $class, string $method): bool
    {
        if ([] !== $this->agents) {
            return true;
        }
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

        if (null !== $refused = $this->agentRefuses($call)) {
            return Decision::deny($refused);
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
            return Decision::pending($hold, '');
        }

        return Decision::allow();
    }

    /** A held call becomes a request for the approvers — unless this user already has too many waiting. */
    private function hold(Invocation $call, string $reason): Decision
    {
        // an approver is not worn down by a flood of requests: past a few waiting, refused
        $mine = array_filter(
            $this->approvals->all(Approval::PENDING, 500),
            fn (Approval $a) => $a->requestedBy === $call->user && !$a->expired($this->expireAfter),
        );
        if (\count($mine) >= $this->maxPendingPerUser) {
            return Decision::deny(sprintf('%d requests already wait for a human', \count($mine)));
        }
        $approval = Approval::for($call, $reason);
        $this->approvals->save($approval);
        $this->events?->dispatch(new ApprovalRequested($approval));

        return Decision::pending($reason, $approval->id);
    }

    /** The agents allowed to call this tool (debug:tool-guard); null when no agent is declared. */
    public function agentsFor(string $tool): ?array
    {
        if ([] === $this->agents) {
            return null;
        }

        return array_keys(array_filter($this->agents, static fn (array $a) => \in_array($tool, $a['tools'], true) || \in_array('*', $a['tools'], true)));
    }

    /** Why the agent behind the call may not make it, or null when it may (or no agent is declared). */
    private function agentRefuses(Invocation $call): ?string
    {
        if ([] === $this->agents) {
            return null;
        }
        if (null === $call->agent || !isset($this->agents[$call->agent])) {
            if ($this->allowUnknownAgent) {
                return null;
            }

            return null === $call->agent
                ? sprintf('no agent identified for this call to "%s"', $call->tool)
                : sprintf('agent "%s" is not declared', $call->agent);
        }
        $tools = $this->agents[$call->agent]['tools'];

        return \in_array($call->tool, $tools, true) || \in_array('*', $tools, true)
            ? null
            : sprintf('agent "%s" may not call "%s"', $call->agent, $call->tool);
    }

    private function withinRate(Invocation $call, RateLimit $rate): bool
    {
        if (null === $this->cache) {
            return false; // a rate that cannot be counted is not a rate
        }
        // Symfony RateLimiter: a fixed window per tool and user, counted under a lock when the
        // app has one, so concurrent calls cannot slip past the limit together
        $factory = new RateLimiterFactory([
            'id' => 'tool_guard.'.hash('xxh128', $call->class.'::'.$call->method),
            'policy' => 'fixed_window', 'limit' => $rate->limit, 'interval' => $rate->seconds.' seconds',
        ], new CacheStorage($this->cache), $this->locks);
        $who = RateLimit::PER_TOOL === $rate->per ? '*' : ($call->user ?? 'anonymous');

        return $factory->create($who)->consume()->isAccepted();
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

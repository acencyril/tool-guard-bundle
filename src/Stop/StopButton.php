<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Stop;

use Acencyril\ToolGuardBundle\Journal\Journal;

/**
 * The stop button, for the approvals page, the console and your own code: halts every AI caller,
 * one agent or one tool (as an AI calls it) — or, in an emergency, everything — across every PHP
 * process, until a human resumes it. Each press and each resume goes to the journal.
 *
 *     $button->stop(Stop::agents('boss', 'refunds look wrong'));   // the AI stops, the back office goes on
 *     $button->resume(Stop::AGENTS, null, 'boss');
 */
final class StopButton
{
    public function __construct(private readonly StopStore $store, private readonly Journal $journal)
    {
    }

    public function stop(Stop $stop): Stop
    {
        $this->store->add($stop);
        $this->journal->record(['event' => 'stop', 'scope' => $stop->scope, 'target' => $stop->target, 'by' => $stop->by, 'reason' => $stop->reason]);

        return $stop;
    }

    /** Lifts one stop. Returns it, or null when nothing was stopped in that scope. */
    public function resume(string $scope, ?string $target, ?string $by): ?Stop
    {
        $lifted = $this->store->remove($scope, Stop::named($scope) ? $target : null);
        if (null !== $lifted) {
            $this->journal->record(['event' => 'resume', 'scope' => $lifted->scope, 'target' => $lifted->target, 'by' => $by, 'reason' => 'stopped since '.$lifted->at->format(\DATE_ATOM)]);
        }

        return $lifted;
    }

    /** @return list<Stop> */
    public function active(): array
    {
        return $this->store->all();
    }

    public function matching(string $tool, ?string $agent, bool $byAi = true): ?Stop
    {
        return Stop::matching($this->store->all(), $tool, $agent, $byAi);
    }
}

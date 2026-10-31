<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Event;

use Acencyril\ToolGuardBundle\Decision;
use Acencyril\ToolGuardBundle\Invocation;

/**
 * Every decision on a guarded call (the profiler panel listens to it). In observe mode, $decision
 * is what was applied (allow) and $wouldHave what enforce mode would have done.
 */
final class ToolCallDecided
{
    public function __construct(
        public readonly Invocation $call,
        public readonly Decision $decision,
        public readonly ?Decision $wouldHave = null,
    ) {
    }
}

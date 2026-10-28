<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Event;

use Acencyril\ToolGuardBundle\Invocation;

/** The guard refused a call (permission, limit, rate). Repeated refusals for one user are worth a look. */
final class ToolCallRefused
{
    public function __construct(public readonly Invocation $call, public readonly string $reason)
    {
    }
}

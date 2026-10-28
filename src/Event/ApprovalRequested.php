<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Event;

use Acencyril\ToolGuardBundle\Approval\Approval;

/** A call now waits for a human. Listen to it to notify the approvers (Notifier, Slack, a push to a phone…). */
final class ApprovalRequested
{
    public function __construct(public readonly Approval $approval)
    {
    }
}

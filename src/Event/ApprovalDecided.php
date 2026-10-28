<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Event;

use Acencyril\ToolGuardBundle\Approval\Approval;

/** A human approved (the tool has run: see $approval->result) or rejected a held call. */
final class ApprovalDecided
{
    public function __construct(public readonly Approval $approval)
    {
    }
}

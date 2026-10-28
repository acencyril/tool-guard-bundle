<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Attribute;

/**
 * Every call waits for a human: it is stored, the approvers are told (event ApprovalRequested),
 * and the tool only runs once someone approves it on the approvals page or in the console. The
 * agent is answered that the request is pending. The approver is never the one asking: asking
 * the person in the conversation would ask the attacker.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class RequiresApproval
{
    public function __construct(public readonly ?string $reason = null)
    {
    }
}

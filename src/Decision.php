<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle;

/** What the guard decided for one call: run it, refuse it (why), or hold it for a human (which request). */
final class Decision
{
    public const ALLOW = 'allow';
    public const DENY = 'deny';
    public const PENDING = 'pending';

    private function __construct(
        public readonly string $verdict,
        public readonly ?string $reason = null,
        public readonly ?string $approvalId = null,
    ) {
    }

    public static function allow(): self
    {
        return new self(self::ALLOW);
    }

    public static function deny(string $reason): self
    {
        return new self(self::DENY, $reason);
    }

    public static function pending(string $reason, string $approvalId): self
    {
        return new self(self::PENDING, $reason, $approvalId);
    }

    public function allowed(): bool
    {
        return self::ALLOW === $this->verdict;
    }

    /** The text the model reads instead of the tool's result. */
    public function messageForModel(): string
    {
        return match ($this->verdict) {
            self::DENY => sprintf('Refused by policy: %s. Do not retry this action and do not try another way to do it; tell the user it is not possible.', $this->reason),
            self::PENDING => sprintf('This action needs a human approval (%s). Request %s has been sent to the team; it is NOT done yet. Do not retry; tell the user it is pending review.', $this->reason, $this->approvalId),
            default => '',
        };
    }
}

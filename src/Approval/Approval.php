<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Approval;

use Acencyril\ToolGuardBundle\Invocation;

/** A held call: what was asked, by whom, why it waits, and what became of it. */
final class Approval
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const FAILED = 'failed';

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $channel,
        public readonly string $tool,
        public readonly string $class,
        public readonly string $method,
        public readonly array $arguments,
        public readonly ?string $requestedBy,
        public readonly string $reason,
        public readonly \DateTimeImmutable $requestedAt,
        public string $status = self::PENDING,
        public ?string $decidedBy = null,
        public ?\DateTimeImmutable $decidedAt = null,
        public ?string $result = null,
    ) {
    }

    public static function for(Invocation $call, string $reason, ?\DateTimeImmutable $now = null): self
    {
        return new self(
            substr(bin2hex(random_bytes(8)), 0, 8),
            $call->channel, $call->tool, $call->class, $call->method, $call->arguments, $call->user, $reason,
            $now ?? new \DateTimeImmutable(),
        );
    }

    public function invocation(): Invocation
    {
        return new Invocation($this->channel, $this->tool, $this->class, $this->method, $this->arguments, $this->requestedBy);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'channel' => $this->channel, 'tool' => $this->tool, 'class' => $this->class, 'method' => $this->method,
            'arguments' => $this->arguments, 'requested_by' => $this->requestedBy, 'reason' => $this->reason,
            'requested_at' => $this->requestedAt->format(\DATE_ATOM), 'status' => $this->status, 'decided_by' => $this->decidedBy,
            'decided_at' => $this->decidedAt?->format(\DATE_ATOM), 'result' => $this->result,
        ];
    }

    /** @param array<string, mixed> $d */
    public static function fromArray(array $d): self
    {
        return new self(
            (string) $d['id'], (string) $d['channel'], (string) $d['tool'], (string) $d['class'], (string) $d['method'],
            (array) $d['arguments'], $d['requested_by'] ?? null, (string) $d['reason'], new \DateTimeImmutable((string) $d['requested_at']),
            (string) $d['status'], $d['decided_by'] ?? null, isset($d['decided_at']) ? new \DateTimeImmutable((string) $d['decided_at']) : null, $d['result'] ?? null,
        );
    }
}

<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Test;

/** What happened to one tool call in a test: it ran, it waits for a human, it was refused by a rule, or the tool itself failed. */
final class ToolCallOutcome
{
    public const RAN = 'ran';
    public const HELD = 'held';
    public const REFUSED = 'refused';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $tool,
        public readonly string $verdict,
        public readonly string $text,
        public readonly ?string $approvalId = null,
    ) {
    }

    /** Reads the answer the model would get: Tool Guard's refusals and holds have a fixed shape. */
    public static function fromText(string $tool, string $text, bool $isError): self
    {
        if (preg_match('/Request ([a-f0-9]{8,})/', $text, $m) && str_contains($text, 'human approval')) {
            return new self($tool, self::HELD, $text, $m[1]);
        }
        if (str_starts_with($text, 'Refused by policy')) {
            return new self($tool, self::REFUSED, $text);
        }

        return new self($tool, $isError ? self::FAILED : self::RAN, $text);
    }

    public function __toString(): string
    {
        return sprintf('%s %s: %s', $this->tool, $this->verdict, $this->text);
    }
}

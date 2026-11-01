<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle;

/**
 * A call that did not run: refused, or held for a human. Thrown by GuardedInvoker so that a
 * workflow, a queue or code an agent wrote stops at the guarded step instead of going on as if
 * it had run. getMessage() is the text a model should read (Decision::messageForModel()).
 */
final class ToolCallBlocked extends \RuntimeException
{
    public function __construct(public readonly Decision $decision)
    {
        parent::__construct($decision->messageForModel());
    }

    public function held(): bool
    {
        return Decision::PENDING === $this->decision->verdict;
    }
}

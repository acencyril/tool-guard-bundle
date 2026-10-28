<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Journal;

/** Every guarded call and every human decision, kept: what an AI did, tried, and was refused. */
interface Journal
{
    /** @param array<string, mixed> $entry */
    public function record(array $entry): void;

    /** @return list<array<string, mixed>> newest first */
    public function latest(int $limit = 100): array;
}

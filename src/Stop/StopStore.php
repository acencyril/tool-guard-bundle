<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Stop;

/**
 * Where the stops live, shared by every PHP process (web workers, consumers, the console) and kept
 * across a deploy. The bundle ships a file store; implement this for Redis, Doctrine…
 *
 * all() must throw when the state cannot be read: the guard then refuses every call (fail closed),
 * because an unreadable stop is not the same as no stop.
 */
interface StopStore
{
    /** Adds the stop, or replaces the one of the same scope and target. */
    public function add(Stop $stop): void;

    /** Lifts the stop of this scope and target; returns it, or null if there was none. */
    public function remove(string $scope, ?string $target = null): ?Stop;

    /** @return list<Stop> */
    public function all(): array;
}

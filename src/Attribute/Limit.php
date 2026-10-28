<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Attribute;

/**
 * A numeric argument has a ceiling. Above it, the call is refused, or (above: Limit::APPROVAL)
 * held for a human to approve. A missing or non-numeric value is refused: a limit that can be
 * skipped by leaving the argument out is no limit.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Limit
{
    public const DENY = 'deny';
    public const APPROVAL = 'approval';

    public function __construct(
        public readonly string $argument,
        public readonly float $max,
        public readonly string $above = self::DENY,
    ) {
        if (!\in_array($above, [self::DENY, self::APPROVAL], true)) {
            throw new \InvalidArgumentException(sprintf('Limit "above" must be "%s" or "%s".', self::DENY, self::APPROVAL));
        }
    }
}

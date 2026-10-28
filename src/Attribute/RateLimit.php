<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Attribute;

/** At most $limit calls per $seconds, per user (or for everyone, per: "tool"). Ten refunds of 49 € are not one of 490 € — unless you count them. */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class RateLimit
{
    public const PER_USER = 'user';
    public const PER_TOOL = 'tool';

    public function __construct(
        public readonly int $limit,
        public readonly int $seconds = 3600,
        public readonly string $per = self::PER_USER,
    ) {
        if ($limit < 1 || $seconds < 1 || !\in_array($per, [self::PER_USER, self::PER_TOOL], true)) {
            throw new \InvalidArgumentException('RateLimit: limit and seconds at least 1, per "user" or "tool".');
        }
    }
}

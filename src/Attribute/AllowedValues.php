<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Attribute;

/**
 * A text argument (or every item of a list) must match one of the patterns, shell-style and
 * case-insensitive: #[AllowedValues('to', ['*@mycompany.com'])]. Any other value is held for a human
 * (otherwise: AllowedValues::APPROVAL, the default) or refused (AllowedValues::DENY). A missing or
 * non-text value counts as not allowed: a rule that can be skipped by leaving the argument out is no rule.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AllowedValues
{
    public const DENY = 'deny';
    public const APPROVAL = 'approval';

    /** @param list<string> $patterns */
    public function __construct(
        public readonly string $argument,
        public readonly array $patterns,
        public readonly string $otherwise = self::APPROVAL,
    ) {
        if ([] === $patterns || [] !== array_filter($patterns, static fn ($p) => !\is_string($p) || '' === $p)) {
            throw new \InvalidArgumentException(sprintf('AllowedValues on "%s" needs at least one non-empty pattern.', $argument));
        }
        if (!\in_array($otherwise, [self::DENY, self::APPROVAL], true)) {
            throw new \InvalidArgumentException(sprintf('AllowedValues "otherwise" must be "%s" or "%s".', self::DENY, self::APPROVAL));
        }
    }

    public function allows(mixed $value): bool
    {
        $items = \is_array($value) ? array_values($value) : [$value];
        if (null === $value || [] === $items) {
            return false;
        }
        foreach ($items as $item) {
            if (!\is_string($item) || !$this->matches($item)) {
                return false;
            }
        }

        return true;
    }

    /** The first value that is not allowed, for the reason given to the model. */
    public function offending(mixed $value): string
    {
        foreach (\is_array($value) ? $value : [$value] as $item) {
            if (!\is_string($item) || !$this->matches($item)) {
                return \is_scalar($item) ? (string) $item : get_debug_type($item);
            }
        }

        return \is_scalar($value) ? (string) $value : get_debug_type($value);
    }

    private function matches(string $item): bool
    {
        foreach ($this->patterns as $p) {
            if (fnmatch(strtolower($p), strtolower(trim($item)), \FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }
}

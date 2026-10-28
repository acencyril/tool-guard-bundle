<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Attribute;

/**
 * The tool runs only if Symfony Security grants $attribute to the user behind the call, with,
 * as subject, the value of the tool argument named $subject (your voters decide: "this order
 * belongs to this customer"). Repeatable; on the class, it covers every tool of the class.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Granted
{
    public function __construct(
        public readonly string $attribute,
        public readonly ?string $subject = null,
        public readonly ?string $message = null,
    ) {
    }
}

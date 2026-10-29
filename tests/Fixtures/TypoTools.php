<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests\Fixtures;

use Acencyril\ToolGuardBundle\Attribute\Limit;

/** A rule on an argument the method does not take: it would refuse every call, so the build fails. */
final class TypoTools
{
    #[Limit('amout', max: 50)]
    public function refund(string $orderId, float $amount): string
    {
        return 'refunded';
    }
}

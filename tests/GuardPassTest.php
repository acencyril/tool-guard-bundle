<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests;

use Acencyril\ToolGuardBundle\Attribute\Limit;
use Acencyril\ToolGuardBundle\DependencyInjection\GuardPass;
use Acencyril\ToolGuardBundle\Tests\Fixtures\ShopTools;
use Acencyril\ToolGuardBundle\Tests\Fixtures\TypoTools;
use PHPUnit\Framework\TestCase;

/** Mistakes in the rules fail the build, not production. */
final class GuardPassTest extends TestCase
{
    public function testARuleOnAnArgumentTheToolDoesNotTakeFailsTheBuild(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TypoTools::refund() has a rule on argument "amout", which it does not take (orderId, amount)');
        self::isGuarded(TypoTools::class);
    }

    public function testCorrectRulesPass(): void
    {
        self::assertTrue(self::isGuarded(ShopTools::class));
    }

    public function testAMinAboveTheMaxIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Limit('amount', max: 10, min: 20);
    }

    private static function isGuarded(string $class): bool
    {
        return (new \ReflectionMethod(GuardPass::class, 'isGuarded'))->invoke(null, $class);
    }
}

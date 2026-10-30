<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests\Demo;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use ToolGuardDemo\Kernel;

/** The demo a juror runs: the Symfony AI agent path, end to end, with a stand-in model. */
final class DemoCommandsTest extends TestCase
{
    public function testAnAgentTalkedIntoEverythingGetsWhatThePolicyAllows(): void
    {
        $kernel = new Kernel('test', true);
        $app = new Application($kernel);
        $tester = new CommandTester($app->find('demo:attack-real-model'));
        $tester->execute(['--mock' => true]);
        $out = $tester->getDisplay();

        self::assertStringContainsString('✓ ran     refund({"orderId":"A-1","amount":20})', $out);
        self::assertStringContainsString('✗ refused order({"orderId":"B-7"})', $out);
        self::assertStringContainsString('⏸ held    refund({"orderId":"A-2","amount":640})', $out);
        self::assertStringContainsString('amount 5000 is above the limit of 1000', $out);
        self::assertStringContainsString('9 tool calls: 1 ran, 2 wait for a human, 6 refused', $out);
        $kernel->shutdown();
    }

    /** The README's one command: both shops, attacked over real HTTP by demo/run.sh. */
    public function testTheOneCommandDemo(): void
    {
        exec('sh '.escapeshellarg(\dirname(__DIR__, 2).'/demo/run.sh').' 2>&1', $out, $code);
        $out = implode("\n", $out);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('1 ran, 2 wait for a human, 6 refused', $out);
        self::assertStringContainsString('all 9 ran. Without Tool Guard, the prompt was the only lock.', $out);
    }
}

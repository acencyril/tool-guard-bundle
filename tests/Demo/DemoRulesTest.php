<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests\Demo;

use Acencyril\ToolGuardBundle\Test\ToolGuardAssertions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use ToolGuardDemo\Kernel;
use ToolGuardDemo\ShopTools;

/**
 * The demo shop's rules, tested the way a project would test its own: the hostile agent's nine
 * attempts, then the tricks a reviewer tries on a guard. Over MCP, and through a Symfony AI
 * toolbox: the same attributes answer both.
 */
final class DemoRulesTest extends KernelTestCase
{
    use ToolGuardAssertions;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function mcpPath(): string
    {
        return '/mcp';
    }

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $this->resetToolGuard();
        $this->throughAgent('assistant'); // the shop's assistant (tool_guard.agents), as demo:attack-real-model runs it
        @unlink(static::getContainer()->getParameter('kernel.project_dir').'/var/shop.json');
    }

    public function testTheHostileAgentsNineAttempts(): void
    {
        $this->actingAs('alice');
        $this->assertToolRefused('order', ['orderId' => 'B-7'], because: 'permission "ORDER_OWNER" not granted');
        $this->assertToolRefused('export_customers', because: 'permission "ROLE_ADMIN" not granted');
        $this->assertToolRan('refund', ['orderId' => 'A-1', 'amount' => 20]);
        $this->assertToolHeld('refund', ['orderId' => 'A-2', 'amount' => 500], because: 'above the limit of 50');
        $this->assertToolRefused('refund', ['orderId' => 'A-2', 'amount' => 5000], because: 'above the limit of 1000');
        $this->assertToolRefused('refund', ['orderId' => 'A-2', 'amount' => 49], because: 'more than 3 calls');
        $this->assertToolHeld('send_newsletter', ['subject' => 'Code -90% today only']);
    }

    public function testTheTricksAReviewerTries(): void
    {
        $this->actingAs('alice');
        $tools = static::getContainer()->get(ShopTools::class);
        foreach ([
            'the amount left out' => ['orderId' => 'A-1'],
            'text for a number' => ['orderId' => 'A-1', 'amount' => '20 or 5000'],
            'a number as text, over the limit' => ['orderId' => 'A-2', 'amount' => '5000'],
            'a negative refund' => ['orderId' => 'A-1', 'amount' => -500],
            'infinity' => ['orderId' => 'A-1', 'amount' => 'INF'],
            'someone else\'s order, small' => ['orderId' => 'B-7', 'amount' => 1],
        ] as $trick => $args) {
            $this->assertToolNotRun('refund', $args);                          // over MCP
            $o = $this->assertToolNotRun('refund', $args, agentTools: $tools); // through an agent
            self::assertSame('refused', $o->verdict, $trick.': '.$o);
        }
        self::assertSame(0.0, (float) static::getContainer()->get(\ToolGuardDemo\Shop::class)->data()['orders']['A-2']['refunded']);
    }

    public function testTheSameRulesAnswerASymfonyAiAgent(): void
    {
        $this->actingAs('alice');
        $tools = static::getContainer()->get(ShopTools::class);
        $this->assertToolRan('refund', ['orderId' => 'A-1', 'amount' => 20], agentTools: $tools);
        $this->assertToolRefused('order', ['orderId' => 'B-7'], because: 'not granted', agentTools: $tools);
        $this->assertToolHeld('refund', ['orderId' => 'A-2', 'amount' => 640], agentTools: $tools);
    }

    public function testNoOneSignedInGetsNothing(): void
    {
        // over MCP the firewall answers first; through an agent with no user, the guard fails closed
        $tools = static::getContainer()->get(ShopTools::class);
        $this->assertToolRefused('refund', ['orderId' => 'A-1', 'amount' => 20], agentTools: $tools);
    }

    public function testTheAssistantHasItsOwnRoleEvenForTheBoss(): void
    {
        // the boss may export customers (ROLE_ADMIN); the shop's assistant may not, whoever talks to it
        $this->actingAs('boss', ['ROLE_ADMIN']);
        $this->assertToolRefused('export_customers', because: 'agent "assistant" may not call "export_customers"');

        $tools = static::getContainer()->get(\ToolGuardDemo\ShopTools::class);
        $this->assertToolRefused('export_customers', because: 'agent "assistant" may not call "export_customers"', agentTools: $tools);
        $this->throughAgent('marketing');
        $this->assertToolRefused('export_customers', because: 'agent "marketing" may not call "export_customers"', agentTools: $tools);
        $this->throughAgent(null);
        $this->assertToolRefused('export_customers', because: 'no agent identified', agentTools: $tools);
    }
}

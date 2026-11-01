<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests;

use Acencyril\ToolGuardBundle\Approval\FilesystemApprovalStore;
use Acencyril\ToolGuardBundle\CurrentUser;
use Acencyril\ToolGuardBundle\Guard;
use Acencyril\ToolGuardBundle\GuardedInvoker;
use Acencyril\ToolGuardBundle\ToolCallBlocked;
use Acencyril\ToolGuardBundle\Approval\Approval;
use Acencyril\ToolGuardBundle\Integration\Agent\GuardToolCalls;
use Acencyril\ToolGuardBundle\Integration\Mcp\GuardedReferenceHandler;
use Acencyril\ToolGuardBundle\Journal\JsonlJournal;
use Acencyril\ToolGuardBundle\Tests\Fixtures\Auth;
use Acencyril\ToolGuardBundle\Tests\Fixtures\ShopTools;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool as McpTool;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** The guard where calls really happen: a Symfony AI toolbox, and the MCP SDK's handler. */
final class IntegrationTest extends TestCase
{
    private string $dir;
    private Guard $guard;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tg-'.bin2hex(random_bytes(4));
        $this->guard = new Guard(new FilesystemApprovalStore($this->dir.'/a'), new JsonlJournal($this->dir.'/j.jsonl'), new Auth(), new ArrayAdapter());
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testASymfonyAiToolboxNeverRunsARefusedOrHeldCall(): void
    {
        $tools = new ShopTools();
        $events = new EventDispatcher();
        $events->addListener(ToolCallRequested::class, new GuardToolCalls($this->guard, new CurrentUser()));
        $toolbox = new Toolbox([$tools], eventDispatcher: $events);

        $ok = $toolbox->execute(new ToolCall('1', 'refund', ['orderId' => 'A-1', 'amount' => 20]));
        self::assertSame('Refunded 20.00 € on A-1', $ok->getResult());

        $other = $toolbox->execute(new ToolCall('2', 'refund', ['orderId' => 'B-7', 'amount' => 20]));
        self::assertStringStartsWith('Refused by policy: permission "ORDER_VIEW" not granted for orderId "B-7"', $other->getResult());

        $big = $toolbox->execute(new ToolCall('3', 'refund', ['orderId' => 'A-1', 'amount' => 500]));
        self::assertStringContainsString('needs a human approval', $big->getResult());

        self::assertSame(['refund A-1 20.00'], $tools->done, 'only the allowed call ran');
        self::assertSame('sunny', $toolbox->execute(new ToolCall('4', 'weather', []))->getResult(), 'unguarded tools untouched');
    }

    public function testAnMcpServerNeverRunsARefusedOrHeldCall(): void
    {
        $tools = new ShopTools();
        $handler = new GuardedReferenceHandler(new ReferenceHandler(new ServiceLocator([ShopTools::class => static fn () => $tools])), $this->guard, new CurrentUser());
        $ref = new ToolReference(new McpTool('refund', null, ['type' => 'object'], null, null), [ShopTools::class, 'refund']);

        $r = $handler->handle($ref, ['orderId' => 'A-1', 'amount' => 20, '_session' => null, '_request' => null]);
        self::assertSame('Refunded 20.00 € on A-1', $r);

        $refused = $handler->handle($ref, ['orderId' => 'B-7', 'amount' => 20]);
        self::assertInstanceOf(CallToolResult::class, $refused);
        self::assertTrue($refused->isError);
        self::assertStringContainsString('not granted for orderId "B-7"', $refused->content[0]->text);

        self::assertSame(['refund A-1 20.00'], $tools->done);
    }

    public function testAWorkflowChainingToolboxCallsIsGuardedAtEveryStep(): void
    {
        // what an agent that writes code does: one script, several tool calls, results passed along
        $tools = new ShopTools();
        $events = new EventDispatcher();
        $events->addListener(ToolCallRequested::class, new GuardToolCalls($this->guard, new CurrentUser()));
        $toolbox = new Toolbox([$tools], eventDispatcher: $events);

        $results = [];
        foreach ([['A-1', 20], ['A-1', 30], ['B-7', 10], ['A-1', 400]] as $i => [$order, $amount]) {
            $results[] = $toolbox->execute(new ToolCall((string) $i, 'refund', ['orderId' => $order, 'amount' => $amount]))->getResult();
        }
        self::assertSame(['refund A-1 20.00', 'refund A-1 30.00'], $tools->done, 'the other order and the large refund never ran, mid-workflow');
        self::assertStringStartsWith('Refused by policy', $results[2]);
        self::assertStringContainsString('needs a human approval', $results[3]);
    }

    public function testYourOwnExecutionLayerGoesThroughTheGuardedInvoker(): void
    {
        $tools = new ShopTools();
        $store = new FilesystemApprovalStore($this->dir.'/a');
        $invoker = new GuardedInvoker($this->guard, new CurrentUser(), new ServiceLocator([ShopTools::class => static fn () => $tools]));

        self::assertSame('Refunded 20.00 € on A-1', $invoker->call(ShopTools::class, 'refund', ['orderId' => 'A-1', 'amount' => 20]));

        try {
            $invoker->call(ShopTools::class, 'refund', ['orderId' => 'B-7', 'amount' => 20]);
            self::fail('a refused call ran');
        } catch (ToolCallBlocked $e) {
            self::assertFalse($e->held());
            self::assertStringContainsString('not granted for orderId "B-7"', $e->getMessage());
        }

        try {
            $invoker->call($tools, 'refund', ['orderId' => 'A-1', 'amount' => 500]);
            self::fail('a held call ran');
        } catch (ToolCallBlocked $e) {
            self::assertTrue($e->held());
            self::assertSame(Approval::PENDING, $store->get((string) $e->decision->approvalId)?->status, 'waiting for a human, like any held call');
            self::assertSame('code', $store->get((string) $e->decision->approvalId)?->channel);
        }

        self::assertSame('sunny', $invoker->call($tools, 'weather', []), 'unguarded tools run as before');
        self::assertSame(['refund A-1 20.00'], $tools->done);

        $this->expectException(\InvalidArgumentException::class);
        $invoker->call(\stdClass::class, 'x', []);
    }
}

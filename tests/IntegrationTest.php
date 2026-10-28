<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests;

use Acencyril\ToolGuardBundle\Approval\FilesystemApprovalStore;
use Acencyril\ToolGuardBundle\CurrentUser;
use Acencyril\ToolGuardBundle\Guard;
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
}

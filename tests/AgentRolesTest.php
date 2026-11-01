<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests;

use Acencyril\ToolGuardBundle\Approval\FilesystemApprovalStore;
use Acencyril\ToolGuardBundle\CurrentUser;
use Acencyril\ToolGuardBundle\Decision;
use Acencyril\ToolGuardBundle\Guard;
use Acencyril\ToolGuardBundle\Integration\Agent\AgentScope;
use Acencyril\ToolGuardBundle\Integration\Agent\GuardToolCalls;
use Acencyril\ToolGuardBundle\Integration\Mcp\GuardedReferenceHandler;
use Acencyril\ToolGuardBundle\Invocation;
use Acencyril\ToolGuardBundle\Journal\JsonlJournal;
use Acencyril\ToolGuardBundle\Tests\Fixtures\Auth;
use Acencyril\ToolGuardBundle\Tests\Fixtures\ShopTools;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool as McpTool;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\Output;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** An agent never gets every right of the person talking to it: a call runs only if the user AND the agent may make it. */
final class AgentRolesTest extends TestCase
{
    private string $dir;
    private FilesystemApprovalStore $store;
    private JsonlJournal $journal;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tg-'.bin2hex(random_bytes(4));
        $this->store = new FilesystemApprovalStore($this->dir.'/a');
        $this->journal = new JsonlJournal($this->dir.'/j.jsonl');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function guard(array $agents = ['support' => ['tools' => ['order', 'refund']], 'marketing' => ['tools' => ['newsletter']]], bool $allowUnknown = false): Guard
    {
        return new Guard($this->store, $this->journal, new Auth(), new ArrayAdapter(), null, 3, null, Guard::ENFORCE, null, $agents, $allowUnknown);
    }

    private static function call(string $tool, array $args, ?string $agent): Invocation
    {
        return new Invocation(Invocation::AGENT, $tool, ShopTools::class, $tool, $args, 'alice', $agent);
    }

    public function testTheUserAndTheAgentMustBothBeAllowed(): void
    {
        $guard = $this->guard();
        self::assertTrue($guard->decide(self::call('order', ['orderId' => 'A-1'], 'support'))->allowed(), 'both allowed');

        $d = $guard->decide(self::call('order', ['orderId' => 'A-1'], 'marketing'));
        self::assertSame(Decision::DENY, $d->verdict, 'alice may see her order, the marketing agent may not');
        self::assertSame('agent "marketing" may not call "order"', $d->reason);

        $d = $guard->decide(self::call('order', ['orderId' => 'B-7'], 'support'));
        self::assertStringContainsString('not granted for orderId "B-7"', (string) $d->reason, 'the agent may, alice may not: still refused');

        self::assertSame('agent "support" may not call "weather"', $guard->decide(self::call('weather', [], 'support'))->reason, 'with agents declared, even a tool without rules is checked');
        self::assertTrue($guard->guards(ShopTools::class, 'weather'));
        self::assertSame(['support'], $guard->agentsFor('refund'));
        self::assertSame([], $guard->agentsFor('weather'));

        $journal = $this->journal->latest(1)[0];
        self::assertSame('support', $journal['agent']);
    }

    public function testAnUnknownOrMissingAgentIsRefusedUnlessAllowed(): void
    {
        self::assertSame('agent "intruder" is not declared', $this->guard()->decide(self::call('order', ['orderId' => 'A-1'], 'intruder'))->reason);
        self::assertSame('no agent identified for this call to "order"', $this->guard()->decide(self::call('order', ['orderId' => 'A-1'], null))->reason);
        self::assertTrue($this->guard(allowUnknown: true)->decide(self::call('order', ['orderId' => 'A-1'], null))->allowed());
        self::assertTrue($this->guard(['all' => ['tools' => ['*']]])->decide(self::call('order', ['orderId' => 'A-1'], 'all'))->allowed(), '"*" allows every tool');
    }

    public function testWithoutAgentsNothingChanges(): void
    {
        $guard = $this->guard([]);
        self::assertTrue($guard->decide(self::call('order', ['orderId' => 'A-1'], null))->allowed());
        self::assertFalse($guard->guards(ShopTools::class, 'weather'));
        self::assertNull($guard->agentsFor('order'));
    }

    public function testAHeldCallRemembersItsAgent(): void
    {
        $d = $this->guard()->decide(self::call('refund', ['orderId' => 'A-1', 'amount' => 500], 'support'));
        self::assertSame(Decision::PENDING, $d->verdict);
        self::assertSame('support', $this->store->get((string) $d->approvalId)?->agent);
        self::assertSame('support', $this->store->get((string) $d->approvalId)?->invocation()->agent);
    }

    public function testASymfonyAiAgentIsIdentifiedByItsNameAroundItsRun(): void
    {
        $scope = new AgentScope();
        $support = new Agent($this->createMock(PlatformInterface::class), 'm', name: 'support');
        $marketing = new Agent($this->createMock(PlatformInterface::class), 'm', name: 'marketing');
        $tools = new ShopTools();
        $events = new EventDispatcher();
        $events->addListener(ToolCallRequested::class, new GuardToolCalls($this->guard(), new CurrentUser(), $scope));
        $toolbox = new Toolbox([$tools], eventDispatcher: $events);

        self::assertNull($scope->current());
        $scope->setAgent($support);
        $scope->processInput(new Input('m', new MessageBag()));
        self::assertSame('Refunded 20.00 € on A-1', $toolbox->execute(new ToolCall('1', 'refund', ['orderId' => 'A-1', 'amount' => 20]))->getResult());

        // a sub-agent called from a tool stacks on top, then gives its parent back
        $scope->setAgent($marketing);
        $scope->processInput(new Input('m', new MessageBag()));
        self::assertStringContainsString('agent "marketing" may not call "refund"', $toolbox->execute(new ToolCall('2', 'refund', ['orderId' => 'A-1', 'amount' => 20]))->getResult());
        $scope->processOutput(new Output('m', new TextResult('done'), new MessageBag()));
        self::assertSame('support', $scope->current());

        $scope->processOutput(new Output('m', new TextResult('done'), new MessageBag()));
        self::assertNull($scope->current());
        self::assertSame(['refund A-1 20.00'], $tools->done);
    }

    public function testAnMcpServerSpeaksForTheAgentItIsDeclaredFor(): void
    {
        $tools = new ShopTools();
        $inner = new ReferenceHandler(new ServiceLocator([ShopTools::class => static fn () => $tools]));
        $ref = new ToolReference(new McpTool('refund', null, ['type' => 'object'], null, null), [ShopTools::class, 'refund']);

        $asSupport = new GuardedReferenceHandler($inner, $this->guard(), new CurrentUser(), 'support');
        self::assertSame('Refunded 20.00 € on A-1', $asSupport->handle($ref, ['orderId' => 'A-1', 'amount' => 20, '_session' => null, '_request' => null]));

        $asMarketing = new GuardedReferenceHandler($inner, $this->guard(), new CurrentUser(), 'marketing');
        $r = $asMarketing->handle($ref, ['orderId' => 'A-1', 'amount' => 20]);
        self::assertInstanceOf(CallToolResult::class, $r);
        self::assertStringContainsString('agent "marketing" may not call "refund"', $r->content[0]->text);
        self::assertSame(['refund A-1 20.00'], $tools->done);
    }
}

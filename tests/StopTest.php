<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests;

use Acencyril\ToolGuardBundle\Approval\Approval;
use Acencyril\ToolGuardBundle\Approval\Approver;
use Acencyril\ToolGuardBundle\Approval\FilesystemApprovalStore;
use Acencyril\ToolGuardBundle\CurrentUser;
use Acencyril\ToolGuardBundle\Decision;
use Acencyril\ToolGuardBundle\Event\ToolCallDecided;
use Acencyril\ToolGuardBundle\Event\ToolCallRefused;
use Acencyril\ToolGuardBundle\Guard;
use Acencyril\ToolGuardBundle\GuardedInvoker;
use Acencyril\ToolGuardBundle\Integration\Agent\AgentScope;
use Acencyril\ToolGuardBundle\Integration\Agent\GuardToolCalls;
use Acencyril\ToolGuardBundle\Integration\Mcp\GuardedReferenceHandler;
use Acencyril\ToolGuardBundle\Invocation;
use Acencyril\ToolGuardBundle\Journal\JsonlJournal;
use Acencyril\ToolGuardBundle\Stop\FilesystemStopStore;
use Acencyril\ToolGuardBundle\Stop\Stop;
use Acencyril\ToolGuardBundle\Stop\StopButton;
use Acencyril\ToolGuardBundle\Tests\Fixtures\Auth;
use Acencyril\ToolGuardBundle\Tests\Fixtures\ShopTools;
use Acencyril\ToolGuardBundle\ToolCallBlocked;
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

/** The stop button: checked before every rule, on every path, across processes; nothing runs while it holds. */
final class StopTest extends TestCase
{
    private string $dir;
    private FilesystemApprovalStore $approvals;
    private JsonlJournal $journal;
    private FilesystemStopStore $stops;
    private StopButton $button;
    private EventDispatcher $events;
    private ShopTools $tools;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tg-stop-'.bin2hex(random_bytes(4));
        $this->approvals = new FilesystemApprovalStore($this->dir.'/approvals');
        $this->journal = new JsonlJournal($this->dir.'/journal.jsonl');
        $this->stops = new FilesystemStopStore($this->dir.'/stops.json');
        $this->button = new StopButton($this->stops, $this->journal);
        $this->events = new EventDispatcher();
        $this->tools = new ShopTools();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function guard(string $mode = Guard::ENFORCE): Guard
    {
        return new Guard($this->approvals, $this->journal, new Auth(), new ArrayAdapter(), $this->events, 3, null, $mode, null, [], false, $this->stops);
    }

    private function approver(): Approver
    {
        $tools = $this->tools;

        return new Approver($this->approvals, new ServiceLocator([ShopTools::class => static fn () => $tools]), $this->journal, null, null, false, $this->stops);
    }

    private static function call(string $tool, array $args = [], ?string $agent = null): Invocation
    {
        return new Invocation(Invocation::AGENT, $tool, ShopTools::class, $tool, $args, 'alice', $agent);
    }

    public function testStopAllRefusesEveryCallBeforeAnyRuleAndSaysWhoWhenWhy(): void
    {
        $refused = [];
        $decided = [];
        $this->events->addListener(ToolCallRefused::class, function (ToolCallRefused $e) use (&$refused) { $refused[] = $e->reason; });
        $this->events->addListener(ToolCallDecided::class, function (ToolCallDecided $e) use (&$decided) { $decided[] = $e->decision->verdict; });
        $guard = $this->guard();
        $this->button->stop(Stop::all('boss', 'refunds look wrong', new \DateTimeImmutable('2026-11-08 14:02', new \DateTimeZone('UTC'))));

        $d = $guard->decide(self::call('refund', ['orderId' => 'A-1', 'amount' => 20]));
        self::assertSame(Decision::DENY, $d->verdict);
        self::assertSame('everything stopped by boss at 2026-11-08 14:02 +00:00: refunds look wrong', $d->reason);
        self::assertStringStartsWith('Refused by policy: everything stopped by boss', $d->messageForModel());
        self::assertSame(Decision::DENY, $guard->decide(self::call('newsletter', ['subject' => 'x']))->verdict, 'a call that would be held is refused, no request created');
        self::assertSame([], $this->approvals->all());
        self::assertSame(Decision::DENY, $guard->decide(self::call('weather'))->verdict, 'even a tool without any rule');

        self::assertCount(3, $refused, 'the usual refusal event');
        self::assertSame(['deny', 'deny', 'deny'], $decided);
        $entry = $this->journal->latest(1)[0];
        self::assertSame(['call', 'deny', true], [$entry['event'], $entry['verdict'], $entry['stopped']]);
        self::assertSame('stop', $this->journal->latest(4)[3]['event'], 'the press is journaled');

        // the stop came first: no rate counted, so after resume the user still has three refunds
        self::assertNotNull($this->button->resume(Stop::ALL, null, 'boss'));
        self::assertSame('resume', $this->journal->latest(1)[0]['event']);
        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($guard->decide(self::call('refund', ['orderId' => 'A-1', 'amount' => 20]))->allowed());
        }
        self::assertNull($this->journal->verify(), 'stops and resumes are part of the chain');
    }

    public function testOneToolOrOneAgent(): void
    {
        $guard = $this->guard();
        $this->button->stop(Stop::tool('refund', 'boss'));
        self::assertSame(Decision::DENY, $guard->decide(self::call('refund', ['orderId' => 'A-1', 'amount' => 20]))->verdict);
        self::assertStringContainsString('tool "refund" stopped by boss at ', (string) $guard->decide(self::call('refund', ['orderId' => 'A-1', 'amount' => 20]))->reason);
        self::assertTrue($guard->decide(self::call('order', ['orderId' => 'A-1']))->allowed(), 'other tools run');
        $this->button->resume(Stop::TOOL, 'refund', 'boss');

        $this->button->stop(Stop::agent('assistant', 'boss', 'prompt injection on the support chat'));
        $d = $guard->decide(self::call('order', ['orderId' => 'A-1'], 'assistant'));
        self::assertMatchesRegularExpression('/^agent "assistant" stopped by boss at \d{4}-\d\d-\d\d \d\d:\d\d [+-]\d\d:\d\d: prompt injection on the support chat$/', (string) $d->reason);
        self::assertTrue($guard->decide(self::call('order', ['orderId' => 'A-1'], 'marketing'))->allowed(), 'another agent runs');
        self::assertTrue($guard->decide(self::call('order', ['orderId' => 'A-1']))->allowed(), 'a call from no agent runs');

        // the broadest stop is the one named: it is the last one to lift
        $this->button->stop(Stop::all('ops'));
        self::assertStringStartsWith('everything stopped by ops', (string) $guard->decide(self::call('order', ['orderId' => 'A-1'], 'assistant'))->reason);
    }

    /**
     * Stopping the AI never stops the business: with the agent stopped, the same refund still runs
     * from the back office, with its rules; only the emergency stop halts it too.
     */
    public function testAStoppedAgentLeavesTheBackOfficeWorkingWithItsRules(): void
    {
        // an app that declares its agents and refuses calls from no known agent
        $guard = new Guard($this->approvals, $this->journal, new Auth(), new ArrayAdapter(), $this->events, 3, null, Guard::ENFORCE, null, ['assistant' => ['tools' => ['refund']]], false, $this->stops);
        $tools = $this->tools;
        $invoker = new GuardedInvoker($guard, new CurrentUser(), new ServiceLocator([ShopTools::class => static fn () => $tools]));
        $asAgent = static fn (float $amount) => $invoker->call(ShopTools::class, 'refund', ['orderId' => 'A-1', 'amount' => $amount], 'refund', agent: 'assistant');
        $asHuman = static fn (float $amount) => $invoker->call(ShopTools::class, 'refund', ['orderId' => 'A-1', 'amount' => $amount], 'refund', Invocation::HUMAN);

        $this->button->stop(Stop::agent('assistant', 'boss', 'talked into refunds'));
        try {
            $asAgent(20);
            self::fail('the stopped agent refunded');
        } catch (ToolCallBlocked $e) {
            self::assertStringContainsString('agent "assistant" stopped by boss', $e->getMessage());
        }

        // the same tool, from the back-office form: it runs, and its limits still apply
        self::assertSame('Refunded 20.00 € on A-1', $asHuman(20));
        try {
            $asHuman(500);
            self::fail('a 500 € refund ran without approval');
        } catch (ToolCallBlocked $e) {
            self::assertTrue($e->held(), 'above 50 €: a human approves, as always');
            $heldId = (string) $e->decision->approvalId;
        }
        try {
            $asHuman(5000);
            self::fail('a 5 000 € refund ran');
        } catch (ToolCallBlocked $e) {
            self::assertStringContainsString('amount 5000 is above the limit of 1000', $e->getMessage());
        }
        $entry = $this->journal->latest(1)[0];
        self::assertSame(['human', 'deny'], [$entry['channel'], $entry['verdict']], 'journaled, as a person\'s call');
        self::assertArrayNotHasKey('stopped', $entry);

        // "stop the agents" and a tool stop are for the AI too; the back-office request can be approved
        $this->button->stop(Stop::agents('boss'));
        $this->button->stop(Stop::tool('refund', 'boss'));
        try {
            $asHuman(10);
            self::fail('a fourth refund in the hour ran');
        } catch (ToolCallBlocked $e) {
            self::assertStringContainsString('more than 3 calls in 1 hour(s)', $e->getMessage(), 'not stopped: refused by its own rate limit, which counts the back office too');
        }
        self::assertSame(Approval::APPROVED, $this->approver()->approve($heldId, 'manager')->status);

        // the emergency stop halts every caller
        $this->button->stop(Stop::all('boss', 'incident'));
        try {
            $asHuman(10);
            self::fail('a refund ran during the emergency stop');
        } catch (ToolCallBlocked $e) {
            self::assertStringContainsString('everything stopped by boss', $e->getMessage());
        }
        self::assertSame(['refund A-1 20.00', 'refund A-1 500.00'], $this->tools->done);
    }

    public function testObserveModeStillStops(): void
    {
        $guard = $this->guard(Guard::OBSERVE);
        self::assertTrue($guard->decide(self::call('order', ['orderId' => 'B-7']))->allowed(), 'observe mode lets the rules pass');
        $this->button->stop(Stop::all('boss'));
        $d = $guard->decide(self::call('order', ['orderId' => 'A-1']));
        self::assertSame(Decision::DENY, $d->verdict, 'a stop is a human order, not a rule to observe');
        self::assertArrayNotHasKey('would', $this->journal->latest(1)[0]);
    }

    public function testTheIntegrationsAskTheGuardForEveryToolWhileStopped(): void
    {
        $guard = $this->guard();
        self::assertFalse($guard->guards(ShopTools::class, 'weather', 'weather'));
        $this->button->stop(Stop::tool('refund', 'boss'));
        self::assertFalse($guard->guards(ShopTools::class, 'weather', 'weather'), 'another tool stopped: weather is left alone');
        self::assertTrue($guard->guards(ShopTools::class, 'weather'), 'the tool not named: checked, to be safe');
        $this->button->stop(Stop::agent('assistant', 'boss'));
        self::assertTrue($guard->guards(ShopTools::class, 'weather', 'weather', 'assistant'));
        $this->button->stop(Stop::all('boss'));
        self::assertTrue($guard->guards(ShopTools::class, 'weather', 'weather'));
    }

    public function testNothingRunsWhileStoppedOnAnyPath(): void
    {
        $guard = $this->guard();
        $this->button->stop(Stop::all('boss', 'incident 42'));

        // a Symfony AI toolbox, with the agent noted by AgentScope
        $scope = new AgentScope();
        $events = new EventDispatcher();
        $events->addListener(ToolCallRequested::class, new GuardToolCalls($guard, new CurrentUser(), $scope));
        $toolbox = new Toolbox([$this->tools], eventDispatcher: $events);
        $scope->enter('assistant');
        self::assertStringStartsWith('Refused by policy: everything stopped by boss', (string) $toolbox->execute(new ToolCall('1', 'weather', []))->getResult());
        self::assertStringStartsWith('Refused by policy: everything stopped by boss', (string) $toolbox->execute(new ToolCall('2', 'refund', ['orderId' => 'A-1', 'amount' => 20]))->getResult());
        $scope->leave();

        // an MCP server
        $tools = $this->tools;
        $handler = new GuardedReferenceHandler(new ReferenceHandler(new ServiceLocator([ShopTools::class => static fn () => $tools])), $guard, new CurrentUser(), 'assistant');
        $r = $handler->handle(new ToolReference(new McpTool('refund', null, ['type' => 'object'], null, null), [ShopTools::class, 'refund']), ['orderId' => 'A-1', 'amount' => 20]);
        self::assertInstanceOf(CallToolResult::class, $r);
        self::assertTrue($r->isError);
        self::assertStringContainsString('incident 42', $r->content[0]->text);

        // your own execution layer, even for a tool without rules
        $invoker = new GuardedInvoker($guard, new CurrentUser(), new ServiceLocator([ShopTools::class => static fn () => $tools]));
        try {
            $invoker->call($this->tools, 'weather', []);
            self::fail('a stopped call ran');
        } catch (ToolCallBlocked $e) {
            self::assertFalse($e->held());
        }

        self::assertSame([], $this->tools->done, 'nothing ran');
        self::assertCount(4, array_filter($this->journal->latest(), static fn (array $e) => true === ($e['stopped'] ?? false)));
    }

    public function testAHeldCallCannotBeApprovedWhileStoppedAndStaysHeld(): void
    {
        $guard = $this->guard();
        $held = $guard->decide(self::call('refund', ['orderId' => 'A-1', 'amount' => 500], 'assistant'));
        $news = $guard->decide(self::call('newsletter', ['subject' => 'Promo']));
        self::assertSame(Decision::PENDING, $held->verdict);
        $id = (string) $held->approvalId;

        foreach ([Stop::tool('refund', 'boss', 'audit'), Stop::agent('assistant', 'boss'), Stop::all('boss')] as $stop) {
            $this->button->stop($stop);
            try {
                $this->approver()->approve($id, 'manager');
                self::fail('approved while '.$stop->what().' is stopped');
            } catch (\LogicException $e) {
                self::assertStringContainsString(sprintf('Request "%s" cannot be approved while %s stopped by boss', $id, $stop->what()), $e->getMessage());
                self::assertStringContainsString('the request still waits', $e->getMessage());
            }
            self::assertSame(Approval::PENDING, $this->approvals->get($id)?->status, 'still held, nothing lost');
            self::assertTrue($this->approvals->claim($id) && unlink($this->dir.'/approvals/'.$id.'.claim'), 'not even claimed');
            $this->button->resume($stop->scope, $stop->target, 'boss');
        }

        // rejecting runs nothing: it stays possible while stopped
        $this->button->stop(Stop::all('boss'));
        self::assertSame(Approval::REJECTED, $this->approver()->reject((string) $news->approvalId, 'manager')->status);
        self::assertSame([], $this->tools->done);

        // resumed: the same request is approved and runs once
        $this->button->resume(Stop::ALL, null, 'boss');
        self::assertSame(Approval::APPROVED, $this->approver()->approve($id, 'manager')->status);
        self::assertSame(['refund A-1 500.00'], $this->tools->done);
    }

    public function testAnUnreadableStopStateRefusesEverything(): void
    {
        $guard = $this->guard();
        $held = $guard->decide(self::call('refund', ['orderId' => 'A-1', 'amount' => 500]));
        file_put_contents($this->dir.'/stops.json', '{"half a fi');

        $d = $guard->decide(self::call('order', ['orderId' => 'A-1']));
        self::assertSame('the stop state cannot be read', $d->reason, 'fail closed');
        self::assertTrue($guard->guards(ShopTools::class, 'weather', 'weather'));
        $this->expectExceptionMessage('the stop state cannot be read');
        $this->approver()->approve((string) $held->approvalId, 'manager');
    }

    public function testTheStoreReplacesTheSameScopeAndResumesOnlyWhatIsNamed(): void
    {
        $this->button->stop(Stop::tool('refund', 'a', 'first'));
        $this->button->stop(Stop::tool('refund', 'b', '  second   reason '));
        $this->button->stop(Stop::agent('refund', 'c'));
        self::assertCount(2, $this->stops->all(), 'one stop per scope and name; a tool and an agent may share a name');
        $refund = array_values(array_filter($this->stops->all(), static fn (Stop $s) => Stop::TOOL === $s->scope))[0];
        self::assertSame(['b', 'second reason'], [$refund->by, $refund->reason]);

        self::assertNull($this->button->resume(Stop::ALL, null, 'x'), 'nothing to resume');
        self::assertNull($this->button->resume(Stop::TOOL, 'order', 'x'));
        self::assertSame('tool "refund"', $this->button->resume(Stop::TOOL, 'refund', 'x')?->what());
        self::assertSame(['agent:refund'], array_map(static fn (Stop $s) => $s->key(), $this->stops->all()));
        self::assertSame('resume', $this->journal->latest(1)[0]['event']);
        self::assertSame('agent', (new FilesystemStopStore($this->dir.'/stops.json'))->all()[0]->scope, 'another process reads the same state');

        $this->expectException(\InvalidArgumentException::class);
        new Stop(Stop::TOOL, ' ', 'x', new \DateTimeImmutable());
    }

    /**
     * Eight processes stop ten tools each at the same time, while another one reads in a loop:
     * every stop is kept (no lost update), and no reader ever sees half a file.
     */
    public function testConcurrentProcessesKeepEveryStopAndNeverReadHalfAFile(): void
    {
        $file = $this->dir.'/stops.json';
        $autoload = \dirname(__DIR__).'/vendor/autoload.php';
        $writer = 'require %1$s; $s = new Acencyril\ToolGuardBundle\Stop\FilesystemStopStore(%2$s); for ($i = 0; $i < 10; ++$i) { $s->add(Acencyril\ToolGuardBundle\Stop\Stop::tool("p%3$d-t".$i, "p%3$d", str_repeat("x", 400))); }';
        $reader = 'require %s; $s = new Acencyril\ToolGuardBundle\Stop\FilesystemStopStore(%s); $bad = 0; for ($i = 0; $i < 3000; ++$i) { try { $s->all(); } catch (\Throwable $e) { ++$bad; } } echo $bad;';

        $procs = [];
        $this->stops->add(Stop::tool('seed', 'test')); // the reader starts on an existing file
        $readerPipes = [];
        $readerProc = proc_open([\PHP_BINARY, '-r', sprintf($reader, var_export($autoload, true), var_export($file, true))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $readerPipes);
        for ($p = 0; $p < 8; ++$p) {
            $pipes = [];
            $procs[] = [proc_open([\PHP_BINARY, '-r', sprintf($writer, var_export($autoload, true), var_export($file, true), $p)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
        }
        foreach ($procs as [$proc, $pipes]) {
            $err = stream_get_contents($pipes[2]);
            self::assertSame(0, proc_close($proc), (string) $err);
        }
        $bad = stream_get_contents($readerPipes[1]);
        $err = stream_get_contents($readerPipes[2]);
        self::assertSame(0, proc_close($readerProc), (string) $err);

        self::assertSame('0', trim((string) $bad), 'a reader never saw a half-written file');
        self::assertCount(81, $this->stops->all(), 'no stop lost to a concurrent write');
        self::assertIsArray(json_decode((string) file_get_contents($file), true));
        self::assertSame([], glob($this->dir.'/.stops*') ?: [], 'no temporary file left');
    }
}

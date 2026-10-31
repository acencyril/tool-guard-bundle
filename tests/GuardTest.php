<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests;

use Acencyril\ToolGuardBundle\Approval\Approval;
use Acencyril\ToolGuardBundle\Approval\Approver;
use Acencyril\ToolGuardBundle\Approval\FilesystemApprovalStore;
use Acencyril\ToolGuardBundle\Decision;
use Acencyril\ToolGuardBundle\Event\ApprovalRequested;
use Acencyril\ToolGuardBundle\Event\ToolCallDecided;
use Acencyril\ToolGuardBundle\Guard;
use Acencyril\ToolGuardBundle\Invocation;
use Acencyril\ToolGuardBundle\Journal\JsonlJournal;
use Acencyril\ToolGuardBundle\Tests\Fixtures\Auth;
use Acencyril\ToolGuardBundle\Tests\Fixtures\ShopTools;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class GuardTest extends TestCase
{
    private string $dir;
    private FilesystemApprovalStore $store;
    private JsonlJournal $journal;
    private EventDispatcher $events;
    private Guard $guard;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tg-'.bin2hex(random_bytes(4));
        $this->store = new FilesystemApprovalStore($this->dir.'/approvals');
        $this->journal = new JsonlJournal($this->dir.'/journal.jsonl');
        $this->events = new EventDispatcher();
        $this->guard = new Guard($this->store, $this->journal, new Auth(), new ArrayAdapter(), $this->events);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function call(string $method, array $args, ?string $user = 'alice'): Decision
    {
        return $this->guard->decide(new Invocation(Invocation::AGENT, $method, ShopTools::class, $method, $args, $user));
    }

    public function testAVoterDecidesOnTheArgument(): void
    {
        self::assertTrue($this->call('order', ['orderId' => 'A-1'])->allowed());
        $d = $this->call('order', ['orderId' => 'B-7']);
        self::assertSame(Decision::DENY, $d->verdict);
        self::assertSame('permission "ORDER_VIEW" not granted for orderId "B-7"', $d->reason);
        self::assertStringContainsString('Do not retry', $d->messageForModel());
    }

    public function testMissingSubjectOrSecurityRefuses(): void
    {
        self::assertSame(Decision::DENY, $this->call('order', [])->verdict);
        $noSecurity = new Guard($this->store, $this->journal);
        self::assertSame(Decision::DENY, $noSecurity->decide(new Invocation('agent', 'order', ShopTools::class, 'order', ['orderId' => 'A-1']))->verdict);
    }

    public function testLimitsHoldOrRefuse(): void
    {
        self::assertTrue($this->call('refund', ['orderId' => 'A-1', 'amount' => 20])->allowed());

        $asked = [];
        $this->events->addListener(ApprovalRequested::class, function (ApprovalRequested $e) use (&$asked) { $asked[] = $e->approval; });
        $held = $this->call('refund', ['orderId' => 'A-1', 'amount' => 500]);
        self::assertSame(Decision::PENDING, $held->verdict);
        self::assertSame('amount 500 is above the limit of 50', $held->reason);
        self::assertCount(1, $asked);
        self::assertSame($held->approvalId, $asked[0]->id);
        self::assertSame(['orderId' => 'A-1', 'amount' => 500], $this->store->get((string) $held->approvalId)?->arguments);
        self::assertStringContainsString('NOT done yet', $held->messageForModel());

        $too = $this->call('refund', ['orderId' => 'A-1', 'amount' => 5000]);
        self::assertSame(Decision::DENY, $too->verdict, 'above the hard limit: no approval asked');
        self::assertSame(Decision::DENY, $this->call('refund', ['orderId' => 'A-1', 'amount' => 'lots'])->verdict);
        self::assertSame(Decision::DENY, $this->call('refund', ['orderId' => 'A-1'])->verdict, 'a limit is not skipped by leaving the argument out');
    }

    public function testAFloodOfApprovalRequestsIsRefused(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(Decision::PENDING, $this->call('newsletter', ['subject' => 'n'.$i])->verdict);
        }
        $d = $this->call('newsletter', ['subject' => 'again']);
        self::assertSame('3 requests already wait for a human', $d->reason);
        self::assertSame(Decision::PENDING, $this->call('newsletter', ['subject' => 'x'], 'bob')->verdict);
    }

    public function testRateLimitPerUser(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($this->call('refund', ['orderId' => 'A-1', 'amount' => 10])->allowed());
        }
        $d = $this->call('refund', ['orderId' => 'A-1', 'amount' => 10]);
        self::assertSame('more than 3 calls in 1 hour(s)', $d->reason);
        self::assertTrue($this->call('refund', ['orderId' => 'A-1', 'amount' => 10], 'bob')->allowed(), 'another user has his own count');
    }

    public function testUnguardedToolsAreLeftAlone(): void
    {
        self::assertFalse($this->guard->guards(ShopTools::class, 'weather'));
        self::assertTrue($this->guard->guards(ShopTools::class, 'newsletter'));
    }

    public function testApproveRunsOnceRejectRunsNothing(): void
    {
        $tools = new ShopTools();
        $approver = new Approver($this->store, new ServiceLocator([ShopTools::class => static fn () => $tools]), $this->journal);

        $held = $this->call('refund', ['orderId' => 'A-1', 'amount' => '500']);
        $a = $approver->approve((string) $held->approvalId, 'manager');
        self::assertSame(Approval::APPROVED, $a->status);
        self::assertSame('Refunded 500.00 € on A-1', $a->result);
        self::assertSame(['refund A-1 500.00'], $tools->done, 'the JSON string "500" is coerced like PHP would');
        self::assertSame('manager', $this->store->get($a->id)?->decidedBy);
        try {
            $approver->approve($a->id, 'manager');
            self::fail('decided twice');
        } catch (\LogicException) {
        }

        $news = $this->call('newsletter', ['subject' => 'Promo']);
        self::assertSame('emails to every customer are reviewed', $news->reason);
        self::assertSame(Approval::REJECTED, $approver->reject((string) $news->approvalId, 'manager')->status);
        self::assertSame(['refund A-1 500.00'], $tools->done);
    }

    public function testTheJournalIsAChain(): void
    {
        $this->call('order', ['orderId' => 'A-1']);
        $this->call('order', ['orderId' => 'B-7']);
        $this->call('refund', ['orderId' => 'A-1', 'amount' => 500]);
        self::assertNull($this->journal->verify());
        $latest = $this->journal->latest();
        self::assertSame(['pending', 'deny', 'allow'], array_column($latest, 'verdict'));
        self::assertSame('alice', $latest[0]['user']);

        $file = $this->dir.'/journal.jsonl';
        $lines = file($file);
        unset($lines[1]); // someone removes the refused attempt
        file_put_contents($file, implode('', $lines));
        self::assertSame(2, $this->journal->verify());
    }

    public function testARewrittenJournalIsCaughtByItsNotedHead(): void
    {
        $this->call('order', ['orderId' => 'A-1']);
        $this->call('order', ['orderId' => 'B-7']);
        $head = (string) $this->journal->head();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $head);
        $this->call('order', ['orderId' => 'A-1']);
        self::assertTrue($this->journal->contains($head), 'later lines keep the noted head in the chain');

        // someone rewrites the whole file from scratch: a new chain, valid on its own
        unlink($this->dir.'/journal.jsonl');
        $this->call('order', ['orderId' => 'A-1']);
        self::assertNull($this->journal->verify());
        self::assertFalse($this->journal->contains($head));
    }

    private function approver(ShopTools $tools, ?int $expireAfter = null, bool $fourEyes = false): Approver
    {
        return new Approver($this->store, new ServiceLocator([ShopTools::class => static fn () => $tools]), $this->journal, null, $expireAfter, $fourEyes);
    }

    public function testTwoApproversAtOnceRunTheRefundOnce(): void
    {
        $tools = new ShopTools();
        $held = $this->call('refund', ['orderId' => 'A-1', 'amount' => 500]);
        $id = (string) $held->approvalId;
        // a second approver (another tab, another admin told by Slack) loaded the same pending request
        // and clicked first: its claim is in the store, the decision is not written yet
        self::assertTrue($this->store->claim($id));
        try {
            $this->approver($tools)->approve($id, 'second-admin');
            self::fail('approved while another decision held it');
        } catch (\LogicException $e) {
            self::assertStringContainsString('already being decided', $e->getMessage());
        }
        self::assertSame([], $tools->done, 'the refund did not run a second time');
        self::assertFalse($this->store->claim($id), 'a claim is given once');
    }

    public function testAnOldRequestExpiresInsteadOfRunning(): void
    {
        $tools = new ShopTools();
        $old = Approval::for(new Invocation('agent', 'refund', ShopTools::class, 'refund', ['orderId' => 'A-1', 'amount' => 500], 'alice'), 'amount 500 is above the limit of 50', new \DateTimeImmutable('-2 days'));
        $this->store->save($old);
        try {
            $this->approver($tools, 86400)->approve($old->id, 'manager');
            self::fail('a two-day-old request was approved');
        } catch (\LogicException $e) {
            self::assertStringContainsString('expired', $e->getMessage());
        }
        self::assertSame(Approval::EXPIRED, $this->store->get($old->id)?->status);
        self::assertSame([], $tools->done);
        self::assertSame('expired', $this->journal->latest(1)[0]['status']);

        // an expired request no longer counts against the user's waiting requests
        $guard = new Guard($this->store, $this->journal, new Auth(), new ArrayAdapter(), null, 1, null, Guard::ENFORCE, 86400);
        $stale = Approval::for(new Invocation('agent', 'newsletter', ShopTools::class, 'newsletter', ['subject' => 'old'], 'alice'), 'r', new \DateTimeImmutable('-3 days'));
        $this->store->save($stale);
        self::assertSame(Decision::PENDING, $guard->decide(new Invocation('agent', 'newsletter', ShopTools::class, 'newsletter', ['subject' => 'new'], 'alice'))->verdict);
    }

    public function testFourEyesNobodyApprovesTheirOwnRequest(): void
    {
        $tools = new ShopTools();
        $held = $this->call('refund', ['orderId' => 'A-1', 'amount' => 500], 'boss');
        $approver = $this->approver($tools, null, true);
        try {
            $approver->approve((string) $held->approvalId, 'boss');
            self::fail('the requester approved his own refund');
        } catch (\LogicException $e) {
            self::assertStringContainsString('another approver', $e->getMessage());
        }
        self::assertSame([], $tools->done);
        self::assertSame(Approval::APPROVED, $approver->approve((string) $held->approvalId, 'manager')->status, 'someone else can');
    }

    public function testObserveModeBlocksNothingAndJournalsWhatItWouldHaveDone(): void
    {
        $decided = [];
        $this->events->addListener(ToolCallDecided::class, function (ToolCallDecided $e) use (&$decided) { $decided[] = $e; });
        $guard = new Guard($this->store, $this->journal, new Auth(), new ArrayAdapter(), $this->events, 3, null, Guard::OBSERVE);
        $call = fn (string $m, array $a) => $guard->decide(new Invocation('agent', $m, ShopTools::class, $m, $a, 'alice'));

        self::assertTrue($call('order', ['orderId' => 'B-7'])->allowed());
        self::assertTrue($call('refund', ['orderId' => 'A-1', 'amount' => 500])->allowed());
        self::assertSame([], $this->store->all(), 'no request is created in observe mode');
        $latest = $this->journal->latest();
        self::assertSame(['pending', 'deny'], array_column($latest, 'would'));
        self::assertSame(['allow', 'allow'], array_column($latest, 'verdict'));
        self::assertSame('amount 500 is above the limit of 50', $latest[0]['reason']);
        self::assertSame(Decision::DENY, $decided[0]->wouldHave?->verdict);

        $this->expectException(\InvalidArgumentException::class);
        new Guard($this->store, $this->journal, mode: 'audit');
    }

    public function testEveryDecisionIsAnEventAndTheRulesReadInWords(): void
    {
        $decided = [];
        $this->events->addListener(ToolCallDecided::class, function (ToolCallDecided $e) use (&$decided) { $decided[] = $e->decision->verdict; });
        $this->call('order', ['orderId' => 'A-1']);
        $this->call('order', ['orderId' => 'B-7']);
        $this->call('refund', ['orderId' => 'A-1', 'amount' => 500]);
        self::assertSame(['allow', 'deny', 'pending'], $decided);
        self::assertSame([
            '#[Granted] ORDER_VIEW on orderId',
            '#[RateLimit] 3 per 1 hour(s), per user',
            '#[Limit] amount ≤ 50, above: held for a human',
            '#[Limit] amount ≤ 1000, above: refused',
        ], $this->guard->describe(ShopTools::class, 'refund'));
        self::assertSame([], $this->guard->describe(ShopTools::class, 'weather'));
    }
}

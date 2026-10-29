<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests;

use Acencyril\ToolGuardBundle\Approval\Approval;
use Acencyril\ToolGuardBundle\Approval\Approver;
use Acencyril\ToolGuardBundle\Approval\FilesystemApprovalStore;
use Acencyril\ToolGuardBundle\Decision;
use Acencyril\ToolGuardBundle\Event\ApprovalRequested;
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
}

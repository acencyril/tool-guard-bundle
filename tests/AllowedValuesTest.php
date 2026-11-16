<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests;

use Acencyril\ToolGuardBundle\Approval\FilesystemApprovalStore;
use Acencyril\ToolGuardBundle\Attribute\AllowedValues;
use Acencyril\ToolGuardBundle\Decision;
use Acencyril\ToolGuardBundle\Guard;
use Acencyril\ToolGuardBundle\Invocation;
use Acencyril\ToolGuardBundle\Journal\JsonlJournal;
use Acencyril\ToolGuardBundle\Tests\Fixtures\MailTools;
use PHPUnit\Framework\TestCase;

/** Where a mail may go: the recipient is checked in code, not asked of the model. */
final class AllowedValuesTest extends TestCase
{
    private string $dir;
    private Guard $guard;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tg-av-'.bin2hex(random_bytes(4));
        $this->guard = new Guard(new FilesystemApprovalStore($this->dir.'/approvals'), new JsonlJournal($this->dir.'/journal.jsonl'));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function call(string $method, array $args): Decision
    {
        return $this->guard->decide(new Invocation(Invocation::AGENT, $method, MailTools::class, $method, $args, 'alice'));
    }

    public function testInsideTheCompanyItGoesElsewhereAHumanApproves(): void
    {
        self::assertTrue($this->call('send', ['to' => 'Anna@MyCompany.com', 'body' => 'hi'])->allowed());
        $held = $this->call('send', ['to' => 'thief@gmail.com', 'body' => 'invoices']);
        self::assertSame(Decision::PENDING, $held->verdict);
        self::assertSame('to "thief@gmail.com" is not among the allowed values', $held->reason);
        self::assertSame(Decision::PENDING, $this->call('send', ['to' => ['a@mycompany.com', 'x@gmail.com'], 'body' => ''])->verdict, 'every recipient of a list');
    }

    public function testOtherwiseDenyRefusesAndAMissingValueIsNotAllowed(): void
    {
        self::assertTrue($this->call('forward', ['to' => 'partner@supplier.fr'])->allowed());
        self::assertSame(Decision::DENY, $this->call('forward', ['to' => 'x@gmail.com'])->verdict);
        self::assertSame(Decision::DENY, $this->call('forward', [])->verdict);
        self::assertSame(Decision::DENY, $this->call('forward', ['to' => 42])->verdict);
    }

    public function testDescribedAndChecked(): void
    {
        self::assertContains('#[AllowedValues] to in *@mycompany.com, otherwise: held for a human', $this->guard->describe(MailTools::class, 'send'));
        $this->expectException(\InvalidArgumentException::class);
        new AllowedValues('to', []);
    }
}

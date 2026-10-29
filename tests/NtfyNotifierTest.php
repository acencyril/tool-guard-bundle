<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests;

use Acencyril\ToolGuardBundle\Approval\Approval;
use Acencyril\ToolGuardBundle\Event\ApprovalRequested;
use Acencyril\ToolGuardBundle\Invocation;
use Acencyril\ToolGuardBundle\Notify\NtfyNotifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NtfyNotifierTest extends TestCase
{
    public function testThePhoneGetsThePolicyAndTheRawArguments(): void
    {
        $sent = [];
        $http = new MockHttpClient(function (string $method, string $url, array $o) use (&$sent) {
            $sent = [$method, $url, $o];

            return new MockResponse('{}');
        });
        $a = Approval::for(new Invocation('mcp', 'refund', 'X', 'refund', ['orderId' => 'A-1', 'amount' => 500], 'alice'), 'amount 500 is above the limit of 50');
        (new NtfyNotifier($http, 'https://ntfy.sh/topic-xyz', 'https://shop.test/admin/ai/approvals', 'tk'))(new ApprovalRequested($a));

        self::assertSame(['POST', 'https://ntfy.sh/topic-xyz'], [$sent[0], $sent[1]]);
        self::assertContains('Title: AI action waiting: refund', $sent[2]['headers']);
        self::assertContains('Click: https://shop.test/admin/ai/approvals#r-'.$a->id, $sent[2]['headers']);
        self::assertContains('Authorization: Bearer tk', $sent[2]['headers']);
        self::assertStringStartsWith("amount 500 is above the limit of 50\n{\"orderId\":\"A-1\",\"amount\":500}", $sent[2]['body']);
    }

    public function testAFailedPushNeverBreaksTheGuard(): void
    {
        $http = new MockHttpClient(static fn () => throw new \RuntimeException('offline'));
        $a = Approval::for(new Invocation('agent', 't', 'X', 'm', []), 'r');
        (new NtfyNotifier($http, 'https://ntfy.sh/x'))(new ApprovalRequested($a));
        $this->addToAssertionCount(1);
    }
}

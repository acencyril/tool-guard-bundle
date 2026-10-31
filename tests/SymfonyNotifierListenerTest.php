<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests;

use Acencyril\ToolGuardBundle\Approval\Approval;
use Acencyril\ToolGuardBundle\Event\ApprovalRequested;
use Acencyril\ToolGuardBundle\Invocation;
use Acencyril\ToolGuardBundle\Notify\HeldCallNotification;
use Acencyril\ToolGuardBundle\Notify\SymfonyNotifierListener;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\NotificationEmail;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Component\Notifier\Recipient\RecipientInterface;

final class SymfonyNotifierListenerTest extends TestCase
{
    public function testTheApproversGetThePolicyAndTheRawArgumentsOnTheAppsChannels(): void
    {
        $notifier = new class implements NotifierInterface {
            /** @var list<array{Notification, list<RecipientInterface>}> */
            public array $sent = [];

            public function send(Notification $notification, RecipientInterface ...$recipients): void
            {
                $this->sent[] = [$notification, $recipients];
            }

            /** @return list<RecipientInterface> like Symfony's Notifier: framework.notifier.admin_recipients */
            public function getAdminRecipients(): array
            {
                return [new Recipient('boss@shop.test')];
            }
        };
        $a = Approval::for(new Invocation('mcp', 'refund', 'X', 'refund', ['orderId' => 'A-1', 'amount' => 500], 'alice'), 'amount 500 is above the limit of 50');
        (new SymfonyNotifierListener($notifier, 'urgent', ['chat/slack', 'email'], 'https://shop.test/admin/ai/approvals'))(new ApprovalRequested($a));

        self::assertCount(1, $notifier->sent);
        [$n, $to] = $notifier->sent[0];
        self::assertSame('AI action waiting: refund', $n->getSubject());
        self::assertSame(['chat/slack', 'email'], $n->getChannels(new Recipient('boss@shop.test')));
        self::assertSame('urgent', $n->getImportance());
        self::assertStringStartsWith("amount 500 is above the limit of 50\n{\"orderId\":\"A-1\",\"amount\":500}\nasked by alice", $n->getContent());
        self::assertStringEndsWith('Review: https://shop.test/admin/ai/approvals#r-'.$a->id, $n->getContent());
        self::assertSame(['boss@shop.test'], array_map(static fn (Recipient $r) => $r->getEmail(), $to), 'the admin recipients, as Symfony\'s own alerts');
    }

    public function testChatAndSmsGetTheReasonNotOnlyTheSubject(): void
    {
        $n = (new HeldCallNotification('AI action waiting: refund'))->content("amount 500 is above the limit of 50\n{\"amount\":500}")->emoji('🔒');

        self::assertSame("🔒 AI action waiting: refund\namount 500 is above the limit of 50\n{\"amount\":500}", $n->asChatMessage(new Recipient('boss@shop.test'))?->getSubject());
        self::assertSame('AI action waiting: refund: amount 500 is above the limit of 50', $n->asSmsMessage(new Recipient('', '+33600000000'))?->getSubject());
    }

    public function testAnEmailCarriesAReviewButtonToTheRequest(): void
    {
        $n = (new HeldCallNotification('AI action waiting: refund', ['email'], 'https://shop.test/admin/ai/approvals#r-ab12cd34'))
            ->content('amount 500 is above the limit of 50')->importance(Notification::IMPORTANCE_URGENT);
        $email = $n->asEmailMessage(new Recipient('boss@shop.test'))?->getMessage();

        self::assertInstanceOf(NotificationEmail::class, $email);
        self::assertSame('AI action waiting: refund', $email->getSubject());
        self::assertSame('boss@shop.test', $email->getTo()[0]->getAddress());
        self::assertSame('Review the request', $email->getContext()['action_text']);
        self::assertSame('https://shop.test/admin/ai/approvals#r-ab12cd34', $email->getContext()['action_url']);
        self::assertSame('amount 500 is above the limit of 50', $email->getContext()['content']);
    }

    public function testAFailedNotificationNeverBreaksTheGuard(): void
    {
        $notifier = new class implements NotifierInterface {
            public function send(Notification $notification, RecipientInterface ...$recipients): void
            {
                throw new \RuntimeException('Slack is down');
            }
        };
        (new SymfonyNotifierListener($notifier))(new ApprovalRequested(Approval::for(new Invocation('agent', 't', 'X', 'm', []), 'r')));
        $this->addToAssertionCount(1);
    }
}

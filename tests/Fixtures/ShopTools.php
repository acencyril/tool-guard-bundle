<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests\Fixtures;

use Acencyril\ToolGuardBundle\Attribute\Granted;
use Acencyril\ToolGuardBundle\Attribute\Limit;
use Acencyril\ToolGuardBundle\Attribute\RateLimit;
use Acencyril\ToolGuardBundle\Attribute\RequiresApproval;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/** A customer-service agent's tools, guarded. */
#[AsTool('order', 'Show an order', method: 'order')]
#[AsTool('refund', 'Refund an order', method: 'refund')]
#[AsTool('newsletter', 'Email every customer', method: 'newsletter')]
#[AsTool('weather', 'The weather', method: 'weather')]
final class ShopTools
{
    /** @var list<string> */
    public array $done = [];

    #[Granted('ORDER_VIEW', subject: 'orderId')]
    public function order(string $orderId): string
    {
        $this->done[] = 'order '.$orderId;

        return 'order '.$orderId;
    }

    #[Granted('ORDER_VIEW', subject: 'orderId')]
    #[Limit('amount', max: 50, above: Limit::APPROVAL)]
    #[Limit('amount', max: 1000)]
    #[RateLimit(3, seconds: 3600)]
    public function refund(string $orderId, float $amount): string
    {
        $this->done[] = sprintf('refund %s %.2f', $orderId, $amount);

        return sprintf('Refunded %.2f € on %s', $amount, $orderId);
    }

    #[RequiresApproval('emails to every customer are reviewed')]
    public function newsletter(string $subject): string
    {
        $this->done[] = 'newsletter '.$subject;

        return 'sent';
    }

    public function weather(): string
    {
        return 'sunny';
    }
}

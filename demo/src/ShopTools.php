<?php

declare(strict_types=1);

namespace ToolGuardDemo;

use Acencyril\ToolGuardBundle\Attribute\Granted;
use Acencyril\ToolGuardBundle\Attribute\Limit;
use Acencyril\ToolGuardBundle\Attribute\RateLimit;
use Acencyril\ToolGuardBundle\Attribute\RequiresApproval;
use Mcp\Capability\Attribute\McpTool;

/** The tools a customer-service AI gets over MCP. The rules are the attributes, not a prompt. */
final class ShopTools
{
    public function __construct(private readonly Shop $shop)
    {
    }

    #[McpTool(name: 'order', description: 'Show an order')]
    #[Granted('ORDER_OWNER', subject: 'orderId')]
    public function order(string $orderId): array
    {
        return $this->shop->data()['orders'][$orderId] ?? ['error' => 'no such order'];
    }

    #[McpTool(name: 'refund', description: 'Refund an order, in euros')]
    #[Granted('ORDER_OWNER', subject: 'orderId')]
    #[Limit('amount', max: 50, above: Limit::APPROVAL)]
    #[Limit('amount', max: 1000)]
    #[RateLimit(3, seconds: 3600)]
    public function refund(string $orderId, float $amount): string
    {
        $d = $this->shop->data();
        $d['orders'][$orderId]['refunded'] += $amount;
        $this->shop->save($d);

        return sprintf('Refunded %.2f € on %s', $amount, $orderId);
    }

    #[McpTool(name: 'export_customers', description: 'Every customer with address')]
    #[Granted('ROLE_ADMIN')]
    public function exportCustomers(): array
    {
        return $this->shop->data()['customers'];
    }

    #[McpTool(name: 'send_newsletter', description: 'Email every customer')]
    #[RequiresApproval('an email to every customer is reviewed')]
    public function sendNewsletter(string $subject): string
    {
        return sprintf('Newsletter "%s" sent to %d customers', $subject, \count($this->shop->data()['customers']));
    }
}

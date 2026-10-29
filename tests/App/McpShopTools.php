<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests\App;

use Acencyril\ToolGuardBundle\Attribute\Granted;
use Acencyril\ToolGuardBundle\Attribute\Limit;
use Mcp\Capability\Attribute\McpTool;

/** The shop's tools, exposed over MCP and guarded. Records what really ran. */
final class McpShopTools
{
    public static array $ran = [];

    #[McpTool(name: 'refund', description: 'Refund an order')]
    #[Granted('ORDER_VIEW', subject: 'orderId')]
    #[Limit('amount', max: 50, above: Limit::APPROVAL)]
    public function refund(string $orderId, float $amount): string
    {
        self::$ran[] = sprintf('refund %s %.2f', $orderId, $amount);

        return sprintf('Refunded %.2f € on %s', $amount, $orderId);
    }

    #[McpTool(name: 'ping', description: 'Unguarded')]
    public function ping(): string
    {
        return 'pong';
    }
}

# Tool Guard for Symfony AI and MCP

**A prompt is not a lock.** When an AI agent can refund, email or delete, the rules can't live
in its prompt: a persuasive user talks the model out of them. Tool Guard puts the rules in code,
on the tools themselves. Whatever the model was talked into, a refused call never runs, and a
risky one waits for a human.

It guards the tools of [Symfony AI](https://symfony.com/doc/current/ai/index.html) agents and of
MCP servers ([symfony/mcp-bundle](https://symfony.com/doc/current/ai/bundles/mcp-bundle.html)),
with the same attributes:

```php
use Acencyril\ToolGuardBundle\Attribute\{Granted, Limit, RateLimit, RequiresApproval};

final class OrderTools
{
    #[AsTool('refund', 'Refund an order')]          // Symfony AI agent tool…
    #[McpTool(name: 'refund')]                       // …and/or MCP tool
    #[Granted('ORDER_REFUND', subject: 'orderId')]   // your voter: is this order the user's?
    #[Limit('amount', max: 50, above: Limit::APPROVAL)] // above 50 €: a human approves
    #[Limit('amount', max: 1000)]                    // above 1 000 €: never
    #[RateLimit(3, seconds: 3600)]                   // 3 refunds an hour per user
    public function refund(string $orderId, float $amount): string { /* … */ }

    #[RequiresApproval('emails to all customers are reviewed')]
    public function sendNewsletter(string $subject, string $body): string { /* … */ }
}
```

| Attribute | Effect |
|---|---|
| `#[Granted(attribute, subject: 'arg')]` | Symfony Security decides, with the tool argument as subject (your voters). On MCP tools too. |
| `#[Limit('arg', max, above: deny\|approval)]` | A numeric ceiling. Missing or not a number: refused (a limit can't be skipped by leaving the argument out). |
| `#[RateLimit(n, seconds, per: user\|tool)]` | Ten refunds of 49 € are not one of 490 € — unless you count them. |
| `#[RequiresApproval(reason)]` | Every call waits for a human. |

## What the model sees

- **Refused:** `Refused by policy: amount 5000 is above the limit of 1000. Do not retry…` — the tool never ran.
- **Held:** `This action needs a human approval… Request 3f9a12c0 has been sent to the team; it is NOT done yet.`

## Human approval

A held call is stored with its exact arguments. The approvers are told (listen to
`ApprovalRequested`: Notifier, Slack, a push to a phone), and decide on the approvals page or in
the console. Approve runs the tool once, with the arguments shown; reject runs nothing.

The approver is never the person in the conversation: asking them would ask the attacker.

```yaml
# config/routes/tool_guard.yaml — under your admin
tool_guard:
    resource: '@ToolGuardBundle/config/routes.php'
    prefix: /admin/ai
```

```bash
php bin/console tool-guard:approvals            # what waits
php bin/console tool-guard:approvals approve 3f9a12c0
php bin/console tool-guard:approvals verify     # the journal was not edited
```

## The journal

Every guarded call (allowed, refused, held) and every human decision, one JSON line each, each
line chained to the hash of the previous one: an attempt deleted afterwards breaks the chain.

## Configuration

```yaml
tool_guard:
    approvals_dir: '%kernel.project_dir%/var/tool_guard/approvals'
    journal: '%kernel.project_dir%/var/tool_guard/journal.jsonl'
    approver_role: ROLE_ADMIN
    cache: cache.app          # counts #[RateLimit]
```

The guard fails closed: a permission that cannot be checked (no security, no user) refuses.

On MCP, `#[Granted]` judges the user Symfony Security signed in on the MCP route: use the HTTP
transport behind a firewall. Over stdio there is no user.

## Relation to Symfony AI's `#[IsGrantedTool]`

Symfony AI already checks `#[IsGrantedTool]` on agent tools. Tool Guard adds what it does not
cover: the same permissions on MCP tools, limits, rates, human approval and the journal, with
one set of attributes for both.

## License

MIT

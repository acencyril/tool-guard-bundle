<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Test;

use PHPUnit\Framework\Assert;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Agent permission tests: prove in CI that your tool rules hold, the way you test your voters.
 * No model, no API key: each call takes the real path an agent's call takes — over your MCP
 * server (HTTP, firewall, Tool Guard), or through a Symfony AI toolbox — so a test also fails
 * when the guard is not wired where you think it is.
 *
 *     final class RefundRulesTest extends KernelTestCase
 *     {
 *         use ToolGuardAssertions;
 *
 *         public function testBigRefundsWaitForAHuman(): void
 *         {
 *             $this->actingAs('alice');
 *             $this->assertToolRan('refund', ['orderId' => 'A-1', 'amount' => 20]);
 *             $this->assertToolHeld('refund', ['orderId' => 'A-1', 'amount' => 640]);
 *             $this->assertToolRefused('refund', ['orderId' => 'B-7', 'amount' => 20], because: 'not granted');
 *         }
 *     }
 *
 * Needs framework.test: true. Use it in a KernelTestCase or a WebTestCase.
 */
trait ToolGuardAssertions
{
    private ?KernelBrowser $toolGuardClient = null;
    private ?string $toolGuardSession = null;
    private ?UserInterface $toolGuardUser = null;
    private int $toolGuardRpcId = 0;

    /** The path of your MCP server's route (symfony/mcp-bundle's default is /_mcp). */
    protected function mcpPath(): string
    {
        return '/_mcp';
    }

    /** The firewall that protects the MCP route. */
    protected function mcpFirewall(): string
    {
        return 'main';
    }

    /**
     * The calls that follow are made by this user, signed in as Symfony Security would sign them in
     * (loaded from your user providers when it exists there; else an in-memory user with $roles).
     */
    protected function actingAs(string|UserInterface $user, array $roles = ['ROLE_USER']): void
    {
        if (\is_string($user)) {
            // the application's own user, so the firewall does not see a changed user and sign it out
            $c = static::getContainer();
            try {
                $user = $c->has('security.user_providers') ? $c->get('security.user_providers')->loadUserByIdentifier($user) : new InMemoryUser($user, null, $roles);
            } catch (\Throwable) {
                $user = new InMemoryUser($user, null, $roles);
            }
        }
        $this->toolGuardUser = $user;
        $this->toolGuardSession = null;
        $this->toolGuardClient = null;
    }

    /**
     * Forgets the approvals, the journal and the rate counters of earlier tests (call it in setUp:
     * a #[RateLimit] would otherwise carry over from one test to the next).
     */
    protected function resetToolGuard(): void
    {
        $c = static::getContainer();
        (new Filesystem())->remove([(string) $c->getParameter('tool_guard.approvals_dir'), (string) $c->getParameter('tool_guard.journal')]);
        $pool = (string) $c->getParameter('tool_guard.cache');
        if ($c->has($pool) && method_exists($cache = $c->get($pool), 'clear')) {
            $cache->clear();
        }
    }

    /** Calls a tool of your MCP server, over HTTP, through the firewall and the guard. */
    protected function callMcpTool(string $tool, array $arguments = []): ToolCallOutcome
    {
        $client = $this->toolGuardClient();
        $r = $this->toolGuardRpc($client, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
        if (isset($r['error'])) {
            return new ToolCallOutcome($tool, ToolCallOutcome::FAILED, (string) ($r['error']['message'] ?? json_encode($r['error'])));
        }
        if (!\is_array($r['result'] ?? null)) {
            $res = $client->getResponse();

            return new ToolCallOutcome($tool, ToolCallOutcome::FAILED, sprintf('no MCP result (HTTP %d): %s', $res->getStatusCode(), mb_substr((string) $res->getContent(), 0, 300)));
        }
        $result = $r['result'];

        return ToolCallOutcome::fromText($tool, (string) ($result['content'][0]['text'] ?? ''), (bool) ($result['isError'] ?? false));
    }

    /**
     * Calls a tool through a Symfony AI toolbox, as an agent would, with the application's event
     * dispatcher (where Tool Guard listens). $tools: the service holding the #[AsTool] methods.
     */
    protected function callAgentTool(object $tools, string $tool, array $arguments = []): ToolCallOutcome
    {
        $c = static::getContainer();
        if (null !== $this->toolGuardUser) {
            $c->get('security.token_storage')->setToken(new UsernamePasswordToken($this->toolGuardUser, $this->mcpFirewall(), $this->toolGuardUser->getRoles()));
        }
        $result = (new Toolbox([$tools], eventDispatcher: $c->get('event_dispatcher')))->execute(new ToolCall('test_'.++$this->toolGuardRpcId, $tool, $arguments))->getResult();
        $text = \is_string($result) ? $result : (string) json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return ToolCallOutcome::fromText($tool, $text, false);
    }

    /** The tool ran ($times calls in a row, each one allowed). */
    protected function assertToolRan(string $tool, array $arguments = [], int $times = 1, ?object $agentTools = null): ToolCallOutcome
    {
        for ($i = 1; $i <= $times; ++$i) {
            $o = $this->toolGuardCall($tool, $arguments, $agentTools);
            Assert::assertSame(ToolCallOutcome::RAN, $o->verdict, sprintf('Expected %s to run (call %d of %d). Got: %s', $tool, $i, $times, $o));
        }

        return $o;
    }

    /** The call waits for a human: the tool did not run. */
    protected function assertToolHeld(string $tool, array $arguments = [], ?string $because = null, ?object $agentTools = null): ToolCallOutcome
    {
        $o = $this->toolGuardCall($tool, $arguments, $agentTools);
        Assert::assertSame(ToolCallOutcome::HELD, $o->verdict, sprintf('Expected %s to wait for a human. Got: %s', $tool, $o));
        null === $because || Assert::assertStringContainsString($because, $o->text);

        return $o;
    }

    /** A rule refused the call: the tool did not run. */
    protected function assertToolRefused(string $tool, array $arguments = [], ?string $because = null, ?object $agentTools = null): ToolCallOutcome
    {
        $o = $this->toolGuardCall($tool, $arguments, $agentTools);
        Assert::assertSame(ToolCallOutcome::REFUSED, $o->verdict, sprintf('Expected %s to be refused. Got: %s', $tool, $o));
        null === $because || Assert::assertStringContainsString($because, $o->text);

        return $o;
    }

    /**
     * The tool did not run, whoever stopped it: a rule, or the MCP server's own validation of
     * the arguments (it rejects a missing or mistyped argument before the guard sees it).
     */
    protected function assertToolNotRun(string $tool, array $arguments = [], ?object $agentTools = null): ToolCallOutcome
    {
        $o = $this->toolGuardCall($tool, $arguments, $agentTools);
        Assert::assertNotSame(ToolCallOutcome::RAN, $o->verdict, sprintf('Expected %s not to run. It ran: %s', $tool, $o));

        return $o;
    }

    private function toolGuardCall(string $tool, array $arguments, ?object $agentTools): ToolCallOutcome
    {
        return null === $agentTools ? $this->callMcpTool($tool, $arguments) : $this->callAgentTool($agentTools, $tool, $arguments);
    }

    private function toolGuardClient(): KernelBrowser
    {
        if (null === $this->toolGuardClient) {
            $c = static::getContainer();
            Assert::assertTrue($c->has('test.client'), 'Tool Guard assertions need framework.test: true.');
            $this->toolGuardClient = $c->get('test.client');
            $this->toolGuardClient->disableReboot();
            null === $this->toolGuardUser || $this->toolGuardClient->loginUser($this->toolGuardUser, $this->mcpFirewall());
            $this->toolGuardSession = null;
            $this->toolGuardRpc($this->toolGuardClient, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'tool-guard-test', 'version' => '1']]);
            $this->toolGuardSession = $this->toolGuardClient->getResponse()->headers->get('Mcp-Session-Id');
            $this->toolGuardRpc($this->toolGuardClient, 'notifications/initialized', null, notification: true);
        }

        return $this->toolGuardClient;
    }

    /** @return array<string, mixed> */
    private function toolGuardRpc(KernelBrowser $client, string $method, ?array $params, bool $notification = false): array
    {
        $body = ['jsonrpc' => '2.0', 'method' => $method] + ($notification ? [] : ['id' => ++$this->toolGuardRpcId]) + (null === $params ? [] : ['params' => $params]);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18', 'HTTP_HOST' => 'localhost'];
        null === $this->toolGuardSession || $server['HTTP_MCP_SESSION_ID'] = $this->toolGuardSession;
        $client->request('POST', $this->mcpPath(), server: $server, content: json_encode($body, \JSON_THROW_ON_ERROR));
        $raw = (string) $client->getResponse()->getContent();
        if (preg_match('/^data: (.*)$/m', $raw, $m)) {
            $raw = $m[1];
        }

        return (array) json_decode($raw, true);
    }
}

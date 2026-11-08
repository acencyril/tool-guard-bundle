<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests;

use Acencyril\ToolGuardBundle\Tests\App\Kernel;
use Acencyril\ToolGuardBundle\Tests\App\McpShopTools;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The stop button in a Symfony app: pressed on the approvals page or in the console, felt by the MCP server. */
final class StopAppTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        exec('rm -rf '.escapeshellarg(\dirname(__DIR__).'/var/test-app'));
        McpShopTools::$ran = [];
    }

    public function testStopTheAgentsOnThePageHaltsTheMcpServerAndHeldCallsWait(): void
    {
        $client = static::createClient();
        $session = $this->initialize($client);
        $held = $this->call($client, $session, 'refund', ['orderId' => 'A-1', 'amount' => 500]);
        preg_match('/Request ([a-f0-9]{8})/', $held['content'][0]['text'], $m);

        // only approvers may press it, and only with the page's CSRF token
        $client->request('POST', '/admin/ai/stop', ['scope' => 'agents'], server: self::auth('alice'));
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/admin/ai/stop', ['scope' => 'agents', '_token' => 'forged'], server: self::auth('boss'));
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/admin/ai/stop', server: self::auth('boss'));
        self::assertResponseStatusCodeSame(405);

        $crawler = $client->request('GET', '/admin/ai/approvals', server: self::auth('boss'));
        self::assertCount(0, $crawler->filter('.halted'), 'nothing stopped yet');
        self::assertSame(['⏹ Stop the agents', 'Stop everything'], $crawler->filter('#stop-button .stopall button')->each(static fn ($b) => $b->text()), 'the recommended stop first, the emergency one apart');
        self::assertStringContainsString('your team and your app\'s own code keep using the same tools', $crawler->filter('#stop-button .hint')->text());
        self::assertSame(['⏹ agent marketing', '⏹ agent support', '⏹ ping', '⏹ refund'], $crawler->filter('#stop-button .chips button')->each(static fn ($b) => $b->text()), 'each agent and each tool');
        $client->submit($crawler->selectButton('⏹ Stop the agents')->form(['reason' => 'refunds look wrong']), [], self::auth('boss'));
        self::assertResponseRedirects('/admin/ai/approvals');

        // the page says it in red at the top, and the held refund cannot be approved
        $crawler = $client->request('GET', '/admin/ai/approvals', server: self::auth('boss'));
        self::assertMatchesRegularExpression('/all agents stopped by boss at \d{4}-\d\d-\d\d \d\d:\d\d [+-]\d\d:\d\d: refunds look wrong/', $crawler->filter('.halted')->text());
        self::assertStringContainsString('Only the AI is stopped', $crawler->filter('.halted')->text());
        self::assertSame('stop-button', $crawler->filter('.halted + .card')->attr('id'), 'right above the stop button, before anything else');
        self::assertSame(['Stop everything'], $crawler->filter('#stop-button .stopall button')->each(static fn ($b) => $b->text()), 'the agents are stopped: only the emergency stop is left');
        $card = $crawler->filter('#r-'.$m[1]);
        self::assertStringContainsString('Cannot be approved while all agents stopped by boss', $card->filter('.blocked')->text());
        self::assertNotNull($card->filter('button.ok')->attr('disabled'), 'the Approve button is disabled');
        self::assertSame('stopped', $crawler->filter('tbody tr')->first()->filter('td')->eq(3)->filter('span')->text(), 'journaled');

        // an MCP client is an AI caller: the server refuses everything, the unguarded ping too; nothing runs
        $r = $this->call($client, $session, 'refund', ['orderId' => 'A-1', 'amount' => 20]);
        self::assertTrue($r['isError']);
        self::assertMatchesRegularExpression('/^Refused by policy: all agents stopped by boss at .*: refunds look wrong\./', $r['content'][0]['text']);
        self::assertTrue($this->call($client, $session, 'ping', [])['isError']);
        self::assertSame([], McpShopTools::$ran);

        // approving anyway (an old tab): refused, the page says why, the request still waits
        $token = $client->request('GET', '/admin/ai/approvals', server: self::auth('boss'))->filter('#r-'.$m[1].' input[name=_token]')->attr('value');
        $client->request('POST', '/admin/ai/approvals/'.$m[1].'/approve', ['_token' => $token], server: self::auth('boss'));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('cannot be approved while all agents stopped by boss', $crawler->filter('.notice')->text());
        self::assertCount(1, $crawler->filter('#r-'.$m[1].' .why'), 'still pending');
        self::assertSame([], McpShopTools::$ran);

        // resume: calls run again, and the held refund can be approved, once
        $client->submit($crawler->filter('.halted form')->form(), [], self::auth('boss'));
        $crawler = $client->followRedirect();
        self::assertCount(0, $crawler->filter('.halted'));
        self::assertSame('resumed', $crawler->filter('tbody tr')->first()->filter('td')->eq(3)->filter('span')->text());
        $client->submit($crawler->filter('#r-'.$m[1].' form')->first()->form(), [], self::auth('boss'));
        self::assertSame(['refund A-1 500.00'], McpShopTools::$ran);
        self::assertSame('Refunded 20.00 € on A-1', $this->call($client, $session, 'refund', ['orderId' => 'A-1', 'amount' => 20])['content'][0]['text']);

        // the emergency stop: everything, said as such
        $crawler = $client->request('GET', '/admin/ai/approvals', server: self::auth('boss'));
        $client->submit($crawler->selectButton('Stop everything')->form(['reason' => 'incident']), [], self::auth('boss'));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('everything stopped by boss', $crawler->filter('.halted')->text());
        self::assertStringNotContainsString('Only the AI is stopped', $crawler->filter('.halted')->text());
        self::assertSame(['⏹ Stop the agents'], $crawler->filter('#stop-button .stopall button')->each(static fn ($b) => $b->text()));
        self::assertStringContainsString('everything stopped by boss', $this->call($client, $session, 'ping', [])['content'][0]['text']);
    }

    public function testOneToolAndOneAgentFromThePage(): void
    {
        $client = static::createClient();
        $session = $this->initialize($client);
        $crawler = $client->request('GET', '/admin/ai/approvals', server: self::auth('boss'));
        $client->submit($crawler->filter('#stop-button .chips form')->eq(3)->form(), [], self::auth('boss'));

        $crawler = $client->request('GET', '/admin/ai/approvals', server: self::auth('boss'));
        self::assertStringContainsString('tool "refund" stopped by boss', $crawler->filter('.halted')->text());
        self::assertSame('▶ Resume refund', $crawler->filter('#stop-button .chips button')->eq(3)->text());
        self::assertStringContainsString('tool "refund" stopped by boss', $this->call($client, $session, 'refund', ['orderId' => 'A-1', 'amount' => 20])['content'][0]['text']);
        self::assertSame('pong', $this->call($client, $session, 'ping', [])['content'][0]['text'], 'the other tools run');
        // one test browser for alice's MCP calls and the boss's page: reload for a fresh CSRF token
        $crawler = $client->request('GET', '/admin/ai/approvals', server: self::auth('boss'));
        $client->submit($crawler->filter('#stop-button .chips form')->eq(3)->form(), [], self::auth('boss'));
        self::assertResponseRedirects('/admin/ai/approvals');

        // the shop's MCP server speaks for the support agent: stopping the agent stops the server
        $crawler = $client->request('GET', '/admin/ai/approvals', server: self::auth('boss'));
        self::assertCount(0, $crawler->filter('.halted'));
        $client->submit($crawler->filter('#stop-button .chips form')->eq(1)->form(), [], self::auth('boss'));
        self::assertStringContainsString('agent "support" stopped by boss', $this->call($client, $session, 'ping', [])['content'][0]['text']);

        $crawler = $client->request('GET', '/admin/ai/approvals', server: self::auth('boss'));
        $client->request('POST', '/admin/ai/stop', ['scope' => 'tool', 'target' => '', '_token' => $crawler->filter('#stop-button input[name=_token]')->attr('value')], server: self::auth('boss'));
        self::assertResponseStatusCodeSame(400, 'a tool stop names its tool');
        self::assertSame([], McpShopTools::$ran);
    }

    public function testTheProfilerShowsTheStop(): void
    {
        $client = static::createClient();
        $session = $this->initialize($client);
        $this->console('tool-guard:stop', ['--reason' => 'incident 42']);
        $client->enableProfiler();
        $this->call($client, $session, 'refund', ['orderId' => 'A-1', 'amount' => 20]);
        $panel = $client->getProfile()->getCollector('tool_guard');
        self::assertSame(1, $panel->count('deny'));
        self::assertMatchesRegularExpression('/^all agents stopped by console.* at .*: incident 42$/', $panel->getStops()[0]);
        self::assertStringContainsString('incident 42', $panel->getCalls()[0]['reason']);
    }

    public function testTheCommands(): void
    {
        self::bootKernel();
        [$code, $out] = $this->console('tool-guard:stop', ['--tool' => 'refund', '--reason' => 'audit']);
        self::assertSame(Command::SUCCESS, $code);
        self::assertMatchesRegularExpression('/Stopped: tool "refund" stopped by console.* at .*: audit/', $out);
        self::assertStringContainsString('tool-guard:resume --tool=refund', $out);
        self::assertStringContainsString('Only the AI is stopped', $out);

        [, $out] = $this->console('tool-guard:approvals');
        self::assertStringContainsString('STOPPED', $out, 'the approvals list shows it');
        self::assertStringContainsString('tool "refund" stopped by console', $out);

        [, $out] = $this->console('debug:tool-guard');
        self::assertMatchesRegularExpression('/refund\s.*STOPPED \(tool "refund"\)/', $out, 'the rules show it on the tool');
        self::assertStringContainsString('ping mcp McpShopTools::ping() only the agents\' roles', $out, 'the other tool is not marked');

        self::assertSame(Command::INVALID, $this->console('tool-guard:stop', ['--tool' => 'refund', '--agent' => 'support'])[0]);
        self::assertSame(Command::INVALID, $this->console('tool-guard:stop', ['--everything' => true, '--agent' => 'support'])[0]);
        [$code, $out] = $this->console('tool-guard:resume');
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('Nothing to resume: all agents were not stopped', $out);
        self::assertStringContainsString('tool "refund" stopped', $out, 'what is still stopped is listed');

        [$code, $out] = $this->console('tool-guard:resume', ['--tool' => 'refund']);
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('Resumed: tool "refund"', $out);
        self::assertStringNotContainsString('STOPPED', $this->console('tool-guard:approvals')[1]);
        [, $out] = $this->console('tool-guard:stop', ['--everything' => true]);
        self::assertStringContainsString('Emergency stop: the business actions are halted too', $out);
        self::assertStringContainsString('tool-guard:resume --everything', $out);
        self::assertStringContainsString('Resumed: everything', $this->console('tool-guard:resume', ['--everything' => true])[1]);
        self::assertSame(['resume', 'stop', 'resume', 'stop'], array_column(static::getContainer()->get(\Acencyril\ToolGuardBundle\Journal\Journal::class)->latest(4), 'event'));
    }

    /** @return array{int, string} */
    private function console(string $name, array $input = []): array
    {
        $tester = new CommandTester((new Application(static::$kernel ?? static::bootKernel()))->find($name));
        $code = $tester->execute($input);

        return [$code, preg_replace('/\s+/', ' ', $tester->getDisplay())];
    }

    /** @return array<string, string> */
    private static function auth(string $user): array
    {
        return ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => 'pw', 'HTTP_HOST' => 'localhost'];
    }

    private function initialize(KernelBrowser $client): string
    {
        $this->rpc($client, null, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]]);
        $id = (string) $client->getResponse()->headers->get('Mcp-Session-Id');
        $this->rpc($client, $id, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        return $id;
    }

    /** @return array<string, mixed> */
    private function call(KernelBrowser $client, string $session, string $tool, array $args): array
    {
        $d = $this->rpc($client, $session, ['jsonrpc' => '2.0', 'id' => random_int(2, 99999), 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args]]);
        self::assertArrayHasKey('result', $d, json_encode($d));

        return $d['result'] + ['isError' => false];
    }

    /** @return array<string, mixed> */
    private function rpc(KernelBrowser $client, ?string $session, array $body): array
    {
        $server = self::auth('alice') + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
        if (null !== $session) {
            $server['HTTP_MCP_SESSION_ID'] = $session;
        }
        $client->request('POST', '/mcp', server: $server, content: (string) json_encode($body));
        $raw = (string) $client->getResponse()->getContent();
        if (preg_match('/^data: (.*)$/m', $raw, $m)) {
            $raw = $m[1];
        }

        return (array) json_decode($raw, true);
    }
}

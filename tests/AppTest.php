<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests;

use Acencyril\ToolGuardBundle\Tests\App\Kernel;
use Acencyril\ToolGuardBundle\Tests\App\McpShopTools;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The whole chain in a Symfony app: an MCP client calls tools over HTTP, a human approves on the page. */
final class AppTest extends WebTestCase
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

    public function testAnMcpClientMeetsTheGuardAndAHumanDecides(): void
    {
        $client = static::createClient();
        $session = $this->initialize($client, 'alice');

        self::assertSame('Refunded 20.00 € on A-1', $this->call($client, $session, 'alice', 'refund', ['orderId' => 'A-1', 'amount' => 20])['content'][0]['text']);

        $other = $this->call($client, $session, 'alice', 'refund', ['orderId' => 'B-7', 'amount' => 20]);
        self::assertTrue($other['isError']);
        self::assertStringContainsString('permission "ORDER_VIEW" not granted for orderId "B-7"', $other['content'][0]['text']);

        $held = $this->call($client, $session, 'alice', 'refund', ['orderId' => 'A-1', 'amount' => 500]);
        self::assertTrue($held['isError']);
        self::assertMatchesRegularExpression('/Request ([a-f0-9]{8}) has been sent/', $held['content'][0]['text']);
        preg_match('/Request ([a-f0-9]{8})/', $held['content'][0]['text'], $m);

        self::assertSame('pong', $this->call($client, $session, 'alice', 'ping', [])['content'][0]['text']);
        self::assertSame(['refund A-1 20.00'], McpShopTools::$ran, 'only the allowed refund ran');

        // alice may not open the approvals page; the boss may, and approves
        $client->request('GET', '/admin/ai/approvals', server: self::auth('alice'));
        self::assertResponseStatusCodeSame(403);
        $crawler = $client->request('GET', '/admin/ai/approvals', server: self::auth('boss'));
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('#r-'.$m[1]);
        self::assertStringContainsString('amount 500 is above the limit of 50', $card->text());
        self::assertStringContainsString('"amount": 500', $card->filter('pre')->text());
        $form = $card->filter('form')->first()->form();
        $client->submit($form, [], self::auth('boss'));
        self::assertResponseRedirects();
        self::assertSame(['refund A-1 20.00', 'refund A-1 500.00'], McpShopTools::$ran, 'approved: ran once, as shown');

        // the journal saw it all, in order, and holds
        $crawler = $client->request('GET', '/admin/ai/approvals', server: self::auth('boss'));
        self::assertResponseIsSuccessful();
        self::assertSame('approved', $crawler->filter('#r-'.$m[1].' .tag')->text());
        self::assertStringContainsString('Refunded 500.00 € on A-1', $crawler->filter('#r-'.$m[1].' pre')->text());
        self::assertSame(['approved', 'pending', 'deny', 'allow'], $crawler->filter('tbody tr td:nth-child(4) span')->each(static fn ($n) => $n->text()), 'newest first; the unguarded ping is not journaled');
    }

    /** @return array<string, string> */
    private static function auth(string $user): array
    {
        return ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => 'pw', 'HTTP_HOST' => 'localhost'];
    }

    private function initialize(KernelBrowser $client, string $user): string
    {
        $this->rpc($client, null, $user, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]]);
        $id = (string) $client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotSame('', $id, (string) $client->getResponse()->getContent());
        $this->rpc($client, $id, $user, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        return $id;
    }

    /** @return array<string, mixed> the tools/call result */
    private function call(KernelBrowser $client, string $session, string $user, string $tool, array $args): array
    {
        $d = $this->rpc($client, $session, $user, ['jsonrpc' => '2.0', 'id' => random_int(2, 99999), 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args]]);
        self::assertArrayHasKey('result', $d, json_encode($d));

        return $d['result'] + ['isError' => false];
    }

    /** @return array<string, mixed> */
    private function rpc(KernelBrowser $client, ?string $session, string $user, array $body): array
    {
        $server = self::auth($user) + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
        if (null !== $session) {
            $server['HTTP_MCP_SESSION_ID'] = $session;
        }
        $client->request('POST', '/mcp', server: $server, content: (string) json_encode($body));
        $raw = (string) $client->getResponse()->getContent();
        if (str_contains((string) $client->getResponse()->headers->get('Content-Type'), 'text/event-stream')) {
            preg_match('/^data: (.*)$/m', $raw, $m);
            $raw = $m[1] ?? '';
        }

        return (array) json_decode($raw, true);
    }
}

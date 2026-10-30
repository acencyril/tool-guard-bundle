<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests\Demo;

use ToolGuardDemo\Command\RealModelAttackCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\MockHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use ToolGuardDemo\Kernel;

/** The demo a juror runs: the Symfony AI agent path, end to end, with a stand-in model. */
final class DemoCommandsTest extends TestCase
{
    public function testAnAgentTalkedIntoEverythingGetsWhatThePolicyAllows(): void
    {
        $kernel = new Kernel('test', true);
        $app = new Application($kernel);
        $tester = new CommandTester($app->find('demo:attack-real-model'));
        $tester->execute(['--mock' => true]);
        $out = $tester->getDisplay();

        self::assertStringContainsString('✓ ran     refund({"orderId":"A-1","amount":20})', $out);
        self::assertStringContainsString('✗ refused order({"orderId":"B-7"})', $out);
        self::assertStringContainsString('⏸ held    refund({"orderId":"A-2","amount":640})', $out);
        self::assertStringContainsString('amount 5000 is above the limit of 1000', $out);
        self::assertStringContainsString('9 tool calls: 1 ran, 2 wait for a human, 6 refused', $out);
        $kernel->shutdown();
    }

    /** The README's one command: both shops, attacked over real HTTP by demo/run.sh. */
    public function testTheOneCommandDemo(): void
    {
        exec('sh '.escapeshellarg(\dirname(__DIR__, 2).'/demo/run.sh').' 2>&1', $out, $code);
        $out = implode("\n", $out);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('1 ran, 2 wait for a human, 6 refused', $out);
        self::assertStringContainsString('all 9 ran. Without Tool Guard, the prompt was the only lock.', $out);
    }

    public function testTheRealModelAttackAsksForAKey(): void
    {
        $saved = [$_SERVER['HF_TOKEN'] ?? null, $_SERVER['OPENROUTER_API_KEY'] ?? null];
        unset($_SERVER['HF_TOKEN'], $_SERVER['OPENROUTER_API_KEY']);
        putenv('HF_TOKEN'); putenv('OPENROUTER_API_KEY');
        try {
            $kernel = new Kernel('test', true);
            $tester = new CommandTester((new Application($kernel))->find('demo:attack-real-model'));
            self::assertSame(1, $tester->execute([]));
            self::assertStringContainsString('Set HF_TOKEN', $tester->getDisplay());
        } finally {
            null === $saved[0] || $_SERVER['HF_TOKEN'] = $saved[0];
            null === $saved[1] || $_SERVER['OPENROUTER_API_KEY'] = $saved[1];
        }
    }

    public function testAFailedModelCallShowsWhatTheProviderAnswered(): void
    {
        $saved = $_SERVER['HF_TOKEN'] ?? null;
        $_SERVER['HF_TOKEN'] = 'hf_test';
        $requests = [];
        RealModelAttackCommand::$http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = $options['body'] ?? '';

            return new MockResponse('{"error":"the provider explains what is wrong"}', ['http_code' => 400, 'response_headers' => ['content-type' => 'application/json']]);
        });
        try {
            $kernel = new Kernel('test', true);
            $tester = new CommandTester((new Application($kernel))->find('demo:attack-real-model'));
            $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
            self::assertStringContainsString('provider answered: 400 {"error":"the provider explains what is wrong"}', $tester->getDisplay());
            self::assertStringContainsString('request: POST https://router.huggingface.co/v1/chat/completions', $tester->getDisplay());
            self::assertStringContainsString('"tools"', (string) $requests[0], 'the shop tools are offered to the model');
            // a tool without arguments still carries a parameter schema (Hugging Face refuses it otherwise)
            self::assertStringContainsString('{"name":"export_customers","description":"Every customer with address","parameters":{"type":"object","properties":{}}}', (string) $requests[0]);
        } finally {
            RealModelAttackCommand::$http = null;
            if (null === $saved) {
                unset($_SERVER['HF_TOKEN']);
            } else {
                $_SERVER['HF_TOKEN'] = $saved;
            }
        }
    }
}

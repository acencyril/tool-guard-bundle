<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests\Demo;

use Acencyril\ToolGuardBundle\Test\ToolCallOutcome;
use Acencyril\ToolGuardBundle\Test\ToolGuardAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use ToolGuardDemo\Kernel;
use ToolGuardDemo\ShopTools;

/**
 * The attack corpus (tests/Fixtures/attacks/shop.php): what a customer-service agent gets talked
 * into, played against the demo shop with Tool Guard and against the same shop without it, over
 * MCP and through a Symfony AI toolbox. The README's table is these counts: change a scenario or a
 * rule and testTheCountsTheReadmeQuotes says so.
 */
final class AttackCorpusTest extends KernelTestCase
{
    use ToolGuardAssertions;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function mcpPath(): string
    {
        return '/mcp';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DEMO_UNGUARDED']);
        parent::tearDown();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function scenarios(): iterable
    {
        foreach (require __DIR__.'/../Fixtures/attacks/shop.php' as $i => $s) {
            yield sprintf('%02d %s: %s', $i + 1, $s['family'], $s['said']) => [$s];
        }
    }

    /** @param array{family: string, said: string, as: string, calls: list<array{string, array<string, mixed>}>, expect: string, without?: string} $s */
    #[DataProvider('scenarios')]
    public function testWithToolGuardTheCallGetsItsVerdictOnBothPaths(array $s): void
    {
        foreach (['mcp', 'agent'] as $path) {
            self::assertSame($s['expect'], $this->play($s, $path, guarded: true), sprintf('%s, %s', $s['said'], $path));
        }
    }

    /** @param array{family: string, said: string, as: string, calls: list<array{string, array<string, mixed>}>, expect: string, without?: string} $s */
    #[DataProvider('scenarios')]
    public function testWithoutToolGuardOnlyTheStackItselfStandsInTheWay(array $s): void
    {
        foreach (['mcp', 'agent'] as $path) {
            self::assertSame($s['without'] ?? 'ran', $this->play($s, $path, guarded: false), sprintf('%s, %s', $s['said'], $path));
        }
    }

    public function testTheCountsTheReadmeQuotes(): void
    {
        $with = $without = ['ran' => 0, 'held' => 0, 'refused' => 0, 'stack' => 0];
        $honest = 0;
        foreach (require __DIR__.'/../Fixtures/attacks/shop.php' as $s) {
            if ('honest' === $s['family']) {
                ++$honest;
                self::assertSame('ran', $s['expect'], 'an honest request is served');
                continue;
            }
            self::assertNotSame('ran', $s['expect'], $s['said']);
            ++$with[$s['expect']];
            ++$without[$s['without'] ?? 'ran'];
        }
        self::assertSame(3, $honest);
        self::assertSame(25, array_sum($with), 'attacks in the corpus');
        self::assertSame(['ran' => 0, 'held' => 6, 'refused' => 19, 'stack' => 0], $with, 'with Tool Guard: none runs');
        self::assertSame(['ran' => 19, 'held' => 0, 'refused' => 0, 'stack' => 6], $without, 'without: 19 of 25 run');
    }

    /**
     * Plays the scenario's calls and names what happened to the last one: ran, held, refused, or
     * "stack" when the call never got past the argument validation of the MCP SDK or of Symfony
     * AI's toolbox (with Tool Guard, over MCP, that validation answers before the guard: refused).
     */
    private function play(array $s, string $path, bool $guarded): string
    {
        if ($guarded) {
            unset($_SERVER['DEMO_UNGUARDED']);
        } else {
            $_SERVER['DEMO_UNGUARDED'] = '1';
        }
        self::ensureKernelShutdown();
        self::bootKernel(['environment' => 'test']);
        if ($guarded) {
            $this->resetToolGuard();
        }
        $this->throughAgent($guarded ? 'assistant' : null);
        @unlink(static::getContainer()->getParameter('kernel.project_dir').'/var/shop.json');
        $this->actingAs($s['as'], 'boss' === $s['as'] ? ['ROLE_ADMIN'] : ['ROLE_USER']);
        $tools = static::getContainer()->get(ShopTools::class);
        $verdict = '';
        foreach ($s['calls'] as [$tool, $arguments]) {
            try {
                $verdict = ('mcp' === $path ? $this->callMcpTool($tool, $arguments) : $this->callAgentTool($tools, $tool, $arguments))->verdict;
            } catch (\Symfony\AI\Agent\Toolbox\Exception\InvalidToolCallArgumentsException) {
                $verdict = ToolCallOutcome::FAILED;
            }
        }
        if (ToolCallOutcome::FAILED === $verdict) {
            return $guarded ? 'refused' : 'stack';
        }

        return $verdict;
    }
}

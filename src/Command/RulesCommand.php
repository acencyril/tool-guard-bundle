<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Command;

use Acencyril\ToolGuardBundle\DependencyInjection\GuardPass;
use Acencyril\ToolGuardBundle\Guard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Every tool an AI can call in this app (Symfony AI agent tools and MCP tools) with its rules, in
 * the order the guard checks them — and the tools with none. --fail-on-unguarded makes it a CI
 * check: a new tool cannot reach production without someone deciding its rules.
 */
#[AsCommand(name: 'debug:tool-guard', description: 'List the tools an AI can call, their Tool Guard rules, and the tools without any')]
final class RulesCommand extends Command
{
    /** @param list<class-string> $classes */
    public function __construct(private readonly Guard $guard, private readonly array $classes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fail-on-unguarded', null, InputOption::VALUE_NONE, 'Exit with an error if a tool has no rule (for CI)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        $unguarded = [];
        $withAgents = false;
        foreach (self::tools($this->classes) as [$name, $channels, $class, $method]) {
            $rules = $this->guard->describe($class, $method);
            $agents = null;
            foreach (explode(' / ', $name) as $n) {
                $for = $this->guard->agentsFor($n);
                $agents = null === $for ? $agents : array_unique([...$agents ?? [], ...$for]);
            }
            $withAgents = $withAgents || null !== $agents;
            if ([] === $rules && null === $agents) {
                $unguarded[] = $name;
            }
            $row = [$name, implode(', ', $channels), self::short($class).'::'.$method.'()', [] === $rules ? (null === $agents ? '<fg=yellow>none — the AI calls it freely</>' : 'only the agents\' roles') : implode("\n", $rules)];
            if (null !== $agents) {
                $row[] = [] === $agents ? '<fg=yellow>no agent</>' : implode(', ', $agents);
            }
            $rows[] = $row;
        }
        if ([] === $rows) {
            $io->note('No AI tool found (#[AsTool] or #[McpTool]).');

            return Command::SUCCESS;
        }
        $io->table(['tool', 'for', 'method', 'rules, in the order they are checked', ...($withAgents ? ['agents allowed'] : [])], $rows);
        if ([] === $unguarded) {
            $io->success(sprintf('%d tool(s), every one with at least one rule.', \count($rows)));

            return Command::SUCCESS;
        }
        $message = sprintf('%d tool(s) without any rule: %s.', \count($unguarded), implode(', ', $unguarded));
        if ($input->getOption('fail-on-unguarded')) {
            $io->error($message.' Add a rule, or #[Granted(\'PUBLIC_ACCESS\')] to say it is meant to be open.');

            return Command::FAILURE;
        }
        $io->warning($message);

        return Command::SUCCESS;
    }

    /**
     * @param list<class-string> $classes
     *
     * @return list<array{string, list<string>, class-string, string}> name, channels, class, method
     */
    private static function tools(array $classes): array
    {
        $found = [];
        foreach ($classes as $class) {
            $r = new \ReflectionClass($class);
            foreach ($r->getAttributes(GuardPass::AS_TOOL) as $a) {
                $t = $a->newInstance();
                $found[$class.'::'.$t->method]['names'][] = $t->name;
                $found[$class.'::'.$t->method]['channels']['agent'] = true;
            }
            foreach ($r->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                foreach ($m->getAttributes(GuardPass::MCP_TOOL) as $a) {
                    $found[$class.'::'.$m->getName()]['names'][] = $a->newInstance()->name ?? $m->getName();
                    $found[$class.'::'.$m->getName()]['channels']['mcp'] = true;
                }
            }
        }
        $out = [];
        foreach ($found as $key => $t) {
            [$class, $method] = explode('::', $key, 2);
            $out[] = [implode(' / ', array_unique($t['names'])), array_keys($t['channels']), $class, $method];
        }
        usort($out, static fn ($a, $b) => [$a[2], $a[0]] <=> [$b[2], $b[0]]);

        return $out;
    }

    private static function short(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}

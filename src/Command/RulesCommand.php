<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Command;

use Acencyril\ToolGuardBundle\Guard;
use Acencyril\ToolGuardBundle\Stop\Stop;
use Acencyril\ToolGuardBundle\Stop\StopStore;
use Acencyril\ToolGuardBundle\ToolCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Every tool an AI can call in this app (Symfony AI agent tools and MCP tools) with its rules, in
 * the order the guard checks them — and the tools with none. --fail-on-unguarded makes it a CI
 * check: a new tool cannot reach production without someone deciding its rules. A tool halted by
 * the stop button says so first.
 */
#[AsCommand(name: 'debug:tool-guard', description: 'List the tools an AI can call, their Tool Guard rules, and the tools without any')]
final class RulesCommand extends Command
{
    /** @param list<class-string> $classes */
    public function __construct(private readonly Guard $guard, private readonly array $classes, private readonly ?StopStore $stops = null)
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
        $stops = StopCommand::read($this->stops);
        foreach (ToolCatalog::tools($this->classes) as [$name, $channels, $class, $method]) {
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
            foreach (explode(' / ', $name) as $n) {
                $stop = Stop::matching(array_values(array_filter($stops ?? [], static fn (Stop $s) => Stop::AGENT !== $s->scope)), $n, null);
                if (null !== $stop || null === $stops) {
                    $row[3] = (null === $stops ? '<fg=red>STOPPED (the stop state cannot be read)</>' : '<fg=red>STOPPED</> ('.$stop->what().')')."\n".$row[3];
                    break;
                }
            }
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
        StopCommand::show($io, $stops);
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

    private static function short(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}

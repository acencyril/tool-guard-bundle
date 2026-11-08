<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Command;

use Acencyril\ToolGuardBundle\Stop\Stop;
use Acencyril\ToolGuardBundle\Stop\StopButton;
use Acencyril\ToolGuardBundle\Stop\StopStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * tool-guard:stop [--agent=assistant | --tool=refund | --everything] [--reason=…] — the stop button,
 * in a terminal. Without an option, every AI caller stops (the recommended stop): the same tools
 * still run for people and the app's own code (Invocation::HUMAN). --everything is the emergency
 * stop: every caller, the business actions too. Every PHP process sees it at its next call.
 */
#[AsCommand(name: 'tool-guard:stop', description: 'Stop the AI agents (or one agent, one tool, or --everything) until someone resumes')]
final class StopCommand extends Command
{
    public function __construct(private readonly StopButton $button)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Stop only this agent (tool_guard.agents)')
            ->addOption('tool', null, InputOption::VALUE_REQUIRED, 'Stop only this tool, as an AI calls it (its name as the model sees it)')
            ->addOption('everything', null, InputOption::VALUE_NONE, 'Emergency: stop every caller, people and the app\'s own code included')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why, for the journal and the refused calls');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            [$scope, $target] = self::scope($input);
            $stop = $this->button->stop(new Stop($scope, $target, self::who(), new \DateTimeImmutable(), $input->getOption('reason')));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(sprintf('Stopped: %s. Every matching call is refused and no held call in this scope can be approved until "tool-guard:resume%s".', $stop->describe(), self::options($stop)));
        $io->note(Stop::ALL === $stop->scope
            ? 'Emergency stop: the business actions are halted too, for people and the app\'s own code. To stop only the AI, resume and run tool-guard:stop without --everything.'
            : 'Only the AI is stopped: the same tools still run, with their rules, for people and the app\'s own code (GuardedInvoker, channel: Invocation::HUMAN).');
        self::show($io, $this->button->active());

        return Command::SUCCESS;
    }

    /**
     * @internal --agent, --tool or --everything; none of them: every AI caller
     *
     * @return array{string, ?string}
     */
    public static function scope(InputInterface $input): array
    {
        $tool = $input->getOption('tool');
        $agent = $input->getOption('agent');
        $everything = (bool) $input->getOption('everything');
        if ((null !== $tool) + (null !== $agent) + $everything > 1) {
            throw new \InvalidArgumentException('Give one of --agent, --tool or --everything.');
        }

        return match (true) {
            null !== $tool => [Stop::TOOL, (string) $tool],
            null !== $agent => [Stop::AGENT, (string) $agent],
            $everything => [Stop::ALL, null],
            default => [Stop::AGENTS, null],
        };
    }

    /** @internal who pressed it, as the journal and the refused calls name them */
    public static function who(): string
    {
        $user = \function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;
        $user ??= getenv('USER') ?: getenv('USERNAME') ?: null;

        return null === $user ? 'console' : 'console ('.$user.')';
    }

    /** @internal the options that name the same scope again */
    public static function options(Stop $stop): string
    {
        return match ($stop->scope) {
            Stop::TOOL => ' --tool='.$stop->target,
            Stop::AGENT => ' --agent='.$stop->target,
            Stop::ALL => ' --everything',
            default => '',
        };
    }

    /**
     * @internal the stops, or null when they cannot be read
     *
     * @return list<Stop>|null
     */
    public static function read(?StopStore $store): ?array
    {
        try {
            return null === $store ? [] : $store->all();
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * @internal what is stopped now, in red, as the other commands show it
     *
     * @param list<Stop>|null $stops
     */
    public static function show(SymfonyStyle $io, ?array $stops): void
    {
        if (null === $stops) {
            $io->error('The stop state cannot be read: every AI tool call is refused until it can.');

            return;
        }
        if ([] === $stops) {
            return;
        }
        $io->error(array_merge(['STOPPED — nothing in these scopes runs:'], array_map(static fn (Stop $s) => '  '.$s->describe().'  → tool-guard:resume'.self::options($s), $stops)));
    }
}

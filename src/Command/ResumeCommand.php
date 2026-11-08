<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Command;

use Acencyril\ToolGuardBundle\Stop\Stop;
use Acencyril\ToolGuardBundle\Stop\StopButton;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * tool-guard:resume [--agent=assistant | --tool=refund | --everything] — lifts one stop, named the
 * same way it was pressed (no option: the stop of every agent). The other stops stay, and are listed.
 */
#[AsCommand(name: 'tool-guard:resume', description: 'Lift a stop pressed with tool-guard:stop or on the approvals page')]
final class ResumeCommand extends Command
{
    public function __construct(private readonly StopButton $button)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Resume this agent')
            ->addOption('tool', null, InputOption::VALUE_REQUIRED, 'Resume this tool')
            ->addOption('everything', null, InputOption::VALUE_NONE, 'Lift the emergency stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            [$scope, $target] = StopCommand::scope($input);
            $lifted = $this->button->resume($scope, $target, StopCommand::who());
            $left = $this->button->active();
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if (null === $lifted) {
            $io->warning(sprintf('Nothing to resume: %s %s not stopped.', (new Stop($scope, $target, null, new \DateTimeImmutable()))->what(), Stop::AGENTS === $scope ? 'were' : 'was'));
        } else {
            $io->success(sprintf('Resumed: %s. The requests held in it can be approved again.', $lifted->what()));
        }
        StopCommand::show($io, $left);

        return Command::SUCCESS;
    }
}

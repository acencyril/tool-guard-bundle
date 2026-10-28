<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Command;

use Acencyril\ToolGuardBundle\Approval\ApprovalStore;
use Acencyril\ToolGuardBundle\Approval\Approver;
use Acencyril\ToolGuardBundle\Journal\Journal;
use Acencyril\ToolGuardBundle\Journal\JsonlJournal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** tool-guard:approvals [list|approve <id>|reject <id>|verify] — the approvals page, in a terminal. */
#[AsCommand(name: 'tool-guard:approvals', description: 'List, approve or reject the AI actions waiting for a human; verify the journal')]
final class ApprovalsCommand extends Command
{
    public function __construct(private readonly ApprovalStore $store, private readonly Approver $approver, private readonly Journal $journal)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'list | approve | reject | verify', 'list')
            ->addArgument('id', InputArgument::OPTIONAL, 'The request id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        $id = (string) $input->getArgument('id');
        try {
            switch ($action) {
                case 'list':
                    $rows = array_map(static fn ($a) => [$a->id, $a->tool, $a->requestedBy ?? '—', $a->reason, json_encode($a->arguments, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)], $this->store->all('pending'));
                    [] === $rows ? $io->success('Nothing waiting.') : $io->table(['id', 'tool', 'asked by', 'why', 'arguments'], $rows);

                    return Command::SUCCESS;
                case 'approve':
                case 'reject':
                    $a = 'approve' === $action ? $this->approver->approve($id, 'console') : $this->approver->reject($id, 'console');
                    $io->success(sprintf('%s %s%s', $a->id, $a->status, null !== $a->result ? ': '.$a->result : ''));

                    return 'failed' === $a->status ? Command::FAILURE : Command::SUCCESS;
                case 'verify':
                    if (!$this->journal instanceof JsonlJournal) {
                        $io->note('This journal has no chain to verify.');

                        return Command::SUCCESS;
                    }
                    $broken = $this->journal->verify();
                    null === $broken ? $io->success('The journal chain holds.') : $io->error(sprintf('The journal was changed: line %d does not follow.', $broken));

                    return null === $broken ? Command::SUCCESS : Command::FAILURE;
            }
        } catch (\InvalidArgumentException|\LogicException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->error('Action: list, approve, reject or verify.');

        return Command::INVALID;
    }
}

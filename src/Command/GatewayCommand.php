<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Command;

use Acencyril\ToolGuardBundle\Approval\ApprovalStore;
use Acencyril\ToolGuardBundle\Gateway\McpGateway;
use Acencyril\ToolGuardBundle\Gateway\Pins;
use Acencyril\ToolGuardBundle\Guard;
use Acencyril\ToolGuardBundle\Journal\Journal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tool Guard in front of an MCP server you did not write. In the agent's MCP settings, replace the server's
 * command with `php bin/console tool-guard:gateway config/tool-guard/mail.json`. Held calls show on the same
 * approvals page and in `tool-guard:approvals`; the stop button and the journal are the app's.
 * --tools lists the server's tools (trusted or blocked, and why); --trust NAME / --trust-all trusts them again.
 */
#[AsCommand(name: 'tool-guard:gateway', description: 'Put Tool Guard in front of an MCP server you did not write (stdio)')]
final class GatewayCommand extends Command
{
    public function __construct(
        private readonly Guard $guard,
        private readonly ApprovalStore $approvals,
        private readonly Journal $journal,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('config', InputArgument::REQUIRED, 'The gateway config (JSON), same format as the Python and Node gateways')
            ->addOption('tools', null, InputOption::VALUE_NONE, 'List the server\'s tools, trusted or blocked')
            ->addOption('trust', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Trust these tools again')
            ->addOption('trust-all', null, InputOption::VALUE_NONE, 'Trust every tool the server listed last time')
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'Who trusts them', 'console');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('config');
        $config = json_decode((string) @file_get_contents($file), true);
        if (!\is_array($config) || !isset($config['upstream']['command'])) {
            $output->writeln(sprintf('<error>%s is not a gateway config (JSON with upstream.command).</error>', $file));

            return Command::INVALID;
        }
        $state = (string) ($config['state_dir'] ?? 'var/tool_guard/gateway-'.pathinfo($file, \PATHINFO_FILENAME));
        $pins = new Pins((str_starts_with($state, '/') ? $state : $this->projectDir.'/'.$state).'/pins.json');

        if ($input->getOption('tools')) {
            $data = $pins->load();
            foreach ($data['seen'] ?? [] as $name => $t) {
                $why = $data['blocked'][$name] ?? null;
                $output->writeln($name.': '.(null === $why ? 'trusted' : 'BLOCKED: '.$why));
            }
            if ([] === ($data['seen'] ?? [])) {
                $output->writeln('No tool listed yet: start the agent once through the gateway.');
            }

            return Command::SUCCESS;
        }
        $names = (array) $input->getOption('trust');
        if ([] !== $names || $input->getOption('trust-all')) {
            $done = $pins->trust($input->getOption('trust-all') ? null : array_values(array_map('strval', $names)), (string) $input->getOption('by'));
            $output->writeln('Trusted: '.([] === $done ? 'nothing (unknown names?)' : implode(', ', $done)));

            return [] === $done ? Command::FAILURE : Command::SUCCESS;
        }

        // the MCP conversation owns stdout: nothing else may be written there
        return (new McpGateway($config, $this->guard, $this->approvals, $this->journal, $pins))
            ->run(\STDIN, \STDOUT, (float) ($config['poll_seconds'] ?? 2.0));
    }
}

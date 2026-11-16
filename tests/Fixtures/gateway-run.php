<?php

// Runs the gateway on stdio the way `bin/console tool-guard:gateway` does, without a kernel:
// php gateway-run.php CONFIG STATE_DIR
require \dirname(__DIR__, 2).'/vendor/autoload.php';

use Acencyril\ToolGuardBundle\Approval\FilesystemApprovalStore;
use Acencyril\ToolGuardBundle\Gateway\McpGateway;
use Acencyril\ToolGuardBundle\Gateway\Pins;
use Acencyril\ToolGuardBundle\Guard;
use Acencyril\ToolGuardBundle\Journal\JsonlJournal;
use Acencyril\ToolGuardBundle\Stop\FilesystemStopStore;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

[, $configFile, $state] = $argv;
$config = json_decode((string) file_get_contents($configFile), true);
$approvals = new FilesystemApprovalStore($state.'/approvals');
$journal = new JsonlJournal($state.'/journal.jsonl');
$guard = new Guard($approvals, $journal, null, new FilesystemAdapter('tg', 0, $state.'/cache'), stops: new FilesystemStopStore($state.'/stops.json'));
exit((new McpGateway($config, $guard, $approvals, $journal, new Pins($state.'/pins.json')))->run(\STDIN, \STDOUT, (float) ($config['poll_seconds'] ?? 2.0)));

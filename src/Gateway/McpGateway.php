<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Gateway;

use Acencyril\ToolGuardBundle\Approval\Approval;
use Acencyril\ToolGuardBundle\Approval\ApprovalStore;
use Acencyril\ToolGuardBundle\Attribute\AllowedValues;
use Acencyril\ToolGuardBundle\Attribute\Limit;
use Acencyril\ToolGuardBundle\Attribute\RateLimit;
use Acencyril\ToolGuardBundle\Attribute\RequiresApproval;
use Acencyril\ToolGuardBundle\Decision;
use Acencyril\ToolGuardBundle\Guard;
use Acencyril\ToolGuardBundle\Invocation;
use Acencyril\ToolGuardBundle\Journal\Journal;

/**
 * Tool Guard in front of an MCP server you did not write (mail, drive, CRM, bank...). The agent starts
 * `bin/console tool-guard:gateway config.json` instead of the server; the gateway starts the real server
 * and relays every message on stdio, except:
 *  - tools/call: the rules of the config are checked first (same Guard, same approvals page, same journal
 *    and stop button as the app's own tools); refused or held, the call never reaches the server and the
 *    model gets Tool Guard's message as the tool's answer;
 *  - tools/list: every tool is fingerprinted (Pins); one that appears, changes, or gives the model orders
 *    cannot be called until a person trusts it again.
 * An approved call runs here, at the next check, and its result is stored with the request.
 * The config file is the same as the Python and Node gateways'.
 */
final class McpGateway
{
    public const CLASS_MARKER = 'mcp-gateway';

    /** @var array<string, string> */
    private array $blocked = [];
    /** @var array<int|string, true> */
    private array $listIds = [];
    /** @var array<string, string> our request id => approval id */
    private array $internal = [];
    private bool $ready = false;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly Guard $guard,
        private readonly ApprovalStore $approvals,
        private readonly Journal $journal,
        private readonly Pins $pins,
    ) {
    }

    /**
     * The rules of one upstream tool, and the reason when it is simply refused.
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    public static function rulesFor(array $config, string $tool): array
    {
        $tools = (array) ($config['tools'] ?? []);
        $spec = $tools[$tool] ?? null;
        if (null === $spec) {
            foreach ($tools as $pattern => $s) {
                if (fnmatch((string) $pattern, $tool, \FNM_NOESCAPE)) {
                    $spec = $s;
                    break;
                }
            }
        }
        $rules = ['granted' => [], 'limits' => [], 'values' => [], 'rate' => null, 'approval' => null];
        if (null === $spec) {
            $default = $config['default'] ?? 'allow';
            if ('deny' === $default) {
                return [$rules, sprintf('"%s" has no rule and the default is to refuse', $tool)];
            }
            if ('approval' !== $default) {
                return [$rules, null];
            }
            $spec = ['approval' => sprintf('"%s" has no rule and the default is a human approval', $tool)];
        }
        if (\array_key_exists('deny', $spec)) {
            return [$rules, (string) $spec['deny']];
        }
        foreach ((array) ($spec['limit'] ?? []) as $arg => $l) {
            $rules['limits'][] = new Limit((string) $arg, (float) $l['max'], (string) ($l['above'] ?? Limit::DENY), isset($l['min']) ? (float) $l['min'] : null);
        }
        foreach ((array) ($spec['allowed_values'] ?? []) as $arg => $av) {
            $rules['values'][] = array_is_list($av) ? new AllowedValues((string) $arg, $av) : new AllowedValues((string) $arg, (array) $av['patterns'], (string) ($av['otherwise'] ?? AllowedValues::APPROVAL));
        }
        if (isset($spec['rate'])) {
            $rules['rate'] = new RateLimit((int) $spec['rate']['limit'], (int) $spec['rate']['per_seconds'], (string) ($spec['rate']['per'] ?? RateLimit::PER_USER));
        }
        if (\array_key_exists('approval', $spec)) {
            $rules['approval'] = new RequiresApproval(\is_string($spec['approval']) ? $spec['approval'] : null);
        }

        return [$rules, null];
    }

    /** @param resource $in @param resource $out */
    public function run($in, $out, float $poll = 2.0): int
    {
        $up = (array) $this->config['upstream'];
        $env = array_merge(getenv(), array_map('strval', (array) ($up['env'] ?? [])));
        $proc = proc_open([(string) $up['command'], ...array_map('strval', (array) ($up['args'] ?? []))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => \STDERR], $pipes, $up['cwd'] ?? null, $env);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('Cannot start the MCP server: '.$up['command']);
        }
        [$toServer, $fromServer] = [$pipes[0], $pipes[1]];
        $buffers = ['client' => '', 'server' => ''];
        $nextCheck = microtime(true) + $poll;
        $clientOpen = true;
        while (true) {
            $read = $clientOpen ? [$in, $fromServer] : [$fromServer];
            $w = $e = null;
            $wait = max(0, $nextCheck - microtime(true));
            if (false === @stream_select($read, $w, $e, (int) $wait, (int) (($wait - (int) $wait) * 1e6))) {
                break;
            }
            foreach ($read as $stream) {
                $side = $stream === $in ? 'client' : 'server';
                $chunk = fread($stream, 65536);
                if ('' === $chunk || false === $chunk) {
                    if ('client' === $side) {
                        $clientOpen = false;
                        fclose($toServer);
                        continue;
                    }
                    break 2;
                }
                $buffers[$side] .= $chunk;
                while (false !== $nl = strpos($buffers[$side], "\n")) {
                    $line = substr($buffers[$side], 0, $nl);
                    $buffers[$side] = substr($buffers[$side], $nl + 1);
                    'client' === $side ? $this->fromClient($line, $out, $toServer) : $this->fromServer($line, $out);
                }
            }
            if (microtime(true) >= $nextCheck) {
                $clientOpen && $this->runApproved($toServer);
                $nextCheck = microtime(true) + $poll;
            }
        }
        $clientOpen && @fclose($toServer);
        @fclose($fromServer);

        return proc_close($proc) < 0 ? 1 : 0;
    }

    /** @param resource $out @param resource $toServer */
    public function fromClient(string $line, $out, $toServer): void
    {
        if ('' === trim($line)) {
            return;
        }
        $msg = json_decode($line, true);
        if (!\is_array($msg)) {
            fwrite($toServer, $line."\n");

            return;
        }
        $method = $msg['method'] ?? null;
        if ('tools/list' === $method && \array_key_exists('id', $msg)) {
            $this->listIds[$msg['id']] = true;
        }
        if ('notifications/initialized' === $method) {
            $this->ready = true;
        }
        if ('tools/call' === $method && \array_key_exists('id', $msg)) {
            $p = (array) ($msg['params'] ?? []);
            $decision = $this->decide((string) ($p['name'] ?? ''), \is_array($p['arguments'] ?? null) ? $p['arguments'] : []);
            if (!$decision->allowed()) {
                self::send($out, ['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => ['content' => [['type' => 'text', 'text' => $decision->messageForModel()]], 'isError' => true]]);

                return;
            }
        }
        fwrite($toServer, $line."\n");
    }

    /** @param array<string, mixed> $args */
    public function decide(string $tool, array $args): Decision
    {
        [$rules, $refused] = self::rulesFor($this->config, $tool);
        $reason = $this->blocked[$tool] ?? ($this->pins->load()['blocked'][$tool] ?? null) ?? $refused;
        $call = new Invocation(Invocation::MCP, $tool, self::CLASS_MARKER, $tool, $args, $this->config['user'] ?? null, $this->config['agent'] ?? null);
        if (null !== $reason) {
            $this->journal->record(['event' => 'call', 'channel' => Invocation::MCP, 'tool' => $tool, 'user' => $call->user, 'arguments' => $args, 'verdict' => Decision::DENY, 'reason' => $reason, 'approval' => null, 'gateway' => true]);

            return Decision::deny($reason);
        }

        return $this->guard->decide($call, $rules);
    }

    /** @param resource $out */
    public function fromServer(string $line, $out): void
    {
        $msg = json_decode($line, true);
        if (!\is_array($msg)) {
            fwrite($out, $line."\n");
            fflush($out);

            return;
        }
        $id = $msg['id'] ?? null;
        if (\is_string($id) && isset($this->internal[$id])) {
            $this->finish($this->internal[$id], $msg);
            unset($this->internal[$id]);

            return;
        }
        if (null !== $id && isset($this->listIds[$id])) {
            unset($this->listIds[$id]);
            $this->blocked = $this->pins->review(array_values((array) ($msg['result']['tools'] ?? [])), (bool) ($this->config['trust_on_first_use'] ?? true));
            foreach ($this->blocked as $tool => $why) {
                $this->journal->record(['event' => 'untrusted', 'tool' => $tool, 'reason' => $why]);
            }
        }
        self::send($out, $msg);
    }

    /** @param resource $toServer */
    public function runApproved($toServer): void
    {
        if (!$this->ready) {
            return;
        }
        foreach ($this->approvals->all(Approval::APPROVED, 200) as $a) {
            if (self::CLASS_MARKER === $a->class && $a->queued && !\in_array($a->id, $this->internal, true)) {
                $rid = 'tool-guard-'.$a->id;
                $this->internal[$rid] = $a->id;
                fwrite($toServer, json_encode(['jsonrpc' => '2.0', 'id' => $rid, 'method' => 'tools/call', 'params' => ['name' => $a->tool, 'arguments' => (object) $a->arguments]], \JSON_UNESCAPED_UNICODE)."\n");
            }
        }
    }

    /** @param array<string, mixed> $msg */
    private function finish(string $approvalId, array $msg): void
    {
        $a = $this->approvals->get($approvalId);
        if (null === $a) {
            return;
        }
        if (isset($msg['error'])) {
            $a->status = Approval::FAILED;
            $a->result = mb_substr((string) json_encode($msg['error']), 0, 2000);
        } else {
            $text = implode(' ', array_map(static fn ($c) => \is_array($c) ? (string) ($c['text'] ?? '') : '', (array) ($msg['result']['content'] ?? [])));
            $a->result = mb_substr('' !== $text ? $text : (string) json_encode($msg['result'] ?? null), 0, 2000);
            if (!empty($msg['result']['isError'])) {
                $a->status = Approval::FAILED;
            }
        }
        $a->queued = false;
        $this->approvals->save($a);
        $this->journal->record(['event' => 'approved_run', 'tool' => $a->tool, 'approval' => $a->id, 'status' => $a->status]);
    }

    /** @param resource $out @param array<string, mixed> $msg */
    private static function send($out, array $msg): void
    {
        fwrite($out, json_encode($msg, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n");
        fflush($out);
    }
}

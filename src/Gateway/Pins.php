<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Gateway;

/**
 * The tools of an MCP server a person trusts, as they were when trusted: name, description and input
 * schema, fingerprinted. A tool that appears or changes afterwards cannot be called until a person trusts it
 * again; neither can a tool whose description gives the model orders ("poisoned" tool), even on first use.
 * Same file format as the Python and Node gateways.
 */
final class Pins
{
    /** A description that addresses the model instead of describing the tool. */
    public const SUSPICIOUS = [
        '/ignore (all |any )?(the |your )?(previous|prior|above) (instructions|rules)/',
        "/(do not|don't|never) (tell|inform|mention|reveal)[^.]{0,40}(user|human)/",
        '/<\s*(important|system|instructions?)\s*>/',
        '/(send|forward|copy|upload|post)[^.]{0,60}(to|at) (https?:\/\/|[\w.+-]+@[\w-]+\.)/',
        '/(read|open|include|attach)[^.]{0,40}(\.ssh|id_rsa|\.env|password|api[_ -]?key|token|credentials)/',
        '/before (using|calling) (this|any other) tool/',
        '/ignore[sz]? (toutes |les )?(instructions|consignes) (précédentes|ci-dessus)/u',
        "/ne (le )?dis pas à l'utilisateur/u",
    ];

    public function __construct(private readonly string $file)
    {
    }

    /** @param array<string, mixed> $tool */
    public static function fingerprint(array $tool): string
    {
        $keep = self::sorted(['name' => $tool['name'] ?? null, 'description' => $tool['description'] ?? null, 'inputSchema' => $tool['inputSchema'] ?? null]);

        return substr(hash('sha256', (string) json_encode($keep, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)), 0, 16);
    }

    /** @param array<string, mixed> $tool */
    public static function suspicious(array $tool): ?string
    {
        $text = mb_strtolower((string) json_encode(['d' => $tool['description'] ?? null, 's' => $tool['inputSchema'] ?? null], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        foreach (self::SUSPICIOUS as $re) {
            if (preg_match($re, $text, $m)) {
                return $m[0];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $d = json_decode((string) file_get_contents($this->file), true);

        return \is_array($d) ? $d : ['_corrupt' => true];
    }

    /**
     * Compares what the server lists with what was trusted.
     *
     * @param list<array<string, mixed>> $tools
     *
     * @return array<string, string> tool => why it cannot be called
     */
    public function review(array $tools, bool $trustOnFirstUse): array
    {
        $data = $this->load();
        if (isset($data['_corrupt'])) {
            return array_fill_keys(array_map(static fn ($t) => (string) ($t['name'] ?? '?'), $tools), 'the trusted tools file cannot be read');
        }
        $first = [] === ($data['trusted'] ?? []);
        $data['trusted'] ??= [];
        $data['seen'] = [];
        $blocked = [];
        foreach ($tools as $t) {
            $name = (string) ($t['name'] ?? '');
            $fp = self::fingerprint($t);
            $bad = self::suspicious($t);
            $data['seen'][$name] = ['fingerprint' => $fp, 'description' => $t['description'] ?? null, 'inputSchema' => $t['inputSchema'] ?? null, 'suspicious' => $bad];
            $known = $data['trusted'][$name] ?? null;
            if (null !== $known && $known['fingerprint'] === $fp) {
                continue;
            }
            if ($first && $trustOnFirstUse && null === $bad) {
                $data['trusted'][$name] = ['fingerprint' => $fp, 'at' => date(\DATE_ATOM), 'by' => 'first use'];
                continue;
            }
            $blocked[$name] = null !== $bad
                ? sprintf('tool "%s" is not trusted: its description gives the model instructions ("%s")', $name, $bad)
                : (null !== $known
                    ? sprintf('tool "%s" changed since it was trusted on %s; a person must review it', $name, substr((string) $known['at'], 0, 10))
                    : sprintf('tool "%s" is new and not trusted yet; a person must review it', $name));
        }
        $data['blocked'] = (object) $blocked;
        $this->save($data);

        return $blocked;
    }

    /**
     * @param list<string>|null $names null: every tool seen
     *
     * @return list<string>
     */
    public function trust(?array $names, string $by): array
    {
        $data = $this->load();
        $done = [];
        foreach ($data['seen'] ?? [] as $name => $t) {
            if (null === $names || \in_array($name, $names, true)) {
                $data['trusted'][$name] = ['fingerprint' => $t['fingerprint'], 'at' => date(\DATE_ATOM), 'by' => $by];
                unset($data['blocked'][$name]);
                $done[] = (string) $name;
            }
        }
        $this->save($data);

        return $done;
    }

    /** @param array<string, mixed> $data */
    private function save(array $data): void
    {
        if (!is_dir(\dirname($this->file))) {
            mkdir(\dirname($this->file), 0o775, true);
        }
        $tmp = $this->file.'.'.getmypid().'.tmp';
        file_put_contents($tmp, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        rename($tmp, $this->file);
    }

    private static function sorted(mixed $v): mixed
    {
        if (!\is_array($v)) {
            return $v;
        }
        if (array_is_list($v)) {
            return array_map(self::sorted(...), $v);
        }
        ksort($v);

        return array_map(self::sorted(...), $v);
    }
}

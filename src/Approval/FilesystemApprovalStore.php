<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Approval;

/**
 * One JSON file per request in a folder (default var/tool_guard/approvals), written atomically.
 * A claim is a file created with mode "x": the filesystem lets exactly one process create it.
 */
final class FilesystemApprovalStore implements ApprovalStore
{
    public function __construct(private readonly string $dir)
    {
    }

    public function save(Approval $approval): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(sprintf('Cannot create "%s".', $this->dir));
        }
        $tmp = tempnam($this->dir, '.tmp');
        if (false === $tmp) {
            throw new \RuntimeException(sprintf('Cannot write in "%s".', $this->dir));
        }
        file_put_contents($tmp, json_encode($approval->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        rename($tmp, $this->path($approval->id));
    }

    public function get(string $id): ?Approval
    {
        if (1 !== preg_match('/^[a-f0-9]{8}$/', $id)) {
            return null;
        }
        $raw = @file_get_contents($this->path($id));
        $d = false === $raw ? null : json_decode($raw, true);

        return \is_array($d) ? Approval::fromArray($d) : null;
    }

    public function all(?string $status = null, int $limit = 100): array
    {
        $list = [];
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            $d = json_decode((string) file_get_contents($file), true);
            if (\is_array($d) && (null === $status || ($d['status'] ?? null) === $status)) {
                $list[] = Approval::fromArray($d);
            }
        }
        usort($list, static fn (Approval $a, Approval $b) => $b->requestedAt <=> $a->requestedAt);

        return \array_slice($list, 0, $limit);
    }

    public function claim(string $id): bool
    {
        if (1 !== preg_match('/^[a-f0-9]{8}$/', $id) || !is_file($this->path($id))) {
            return false;
        }
        $h = @fopen($this->dir.'/'.$id.'.claim', 'x');
        if (false === $h) {
            return false;
        }
        fclose($h);

        return true;
    }

    private function path(string $id): string
    {
        return $this->dir.'/'.$id.'.json';
    }
}

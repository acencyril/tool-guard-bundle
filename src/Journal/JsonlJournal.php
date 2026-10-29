<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Journal;

/**
 * One JSON line per event in a file (default var/tool_guard/journal.jsonl), appended under an
 * exclusive lock. Each line carries the hash of the previous one: a line removed or changed
 * afterwards breaks the chain (verify()).
 */
final class JsonlJournal implements Journal
{
    public function __construct(private readonly string $file)
    {
    }

    public function record(array $entry): void
    {
        $dir = \dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create "%s".', $dir));
        }
        $h = fopen($this->file, 'c+');
        if (false === $h) {
            throw new \RuntimeException(sprintf('Cannot open "%s".', $this->file));
        }
        try {
            flock($h, \LOCK_EX);
            $prev = $this->lastHash($h);
            $entry = ['at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.vP')] + $entry + ['prev' => $prev];
            fseek($h, 0, \SEEK_END);
            fwrite($h, json_encode($entry, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
            fflush($h);
        } finally {
            flock($h, \LOCK_UN);
            fclose($h);
        }
    }

    public function latest(int $limit = 100): array
    {
        $lines = @file($this->file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        $out = [];
        foreach (array_reverse(\array_slice($lines, -$limit)) as $line) {
            $d = json_decode($line, true);
            if (\is_array($d)) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /** Null when the chain holds, else the number (1-based) of the first line that does not follow. */
    public function verify(): ?int
    {
        $prev = null;
        foreach ($this->lines() as $i => $line) {
            $d = json_decode($line, true);
            if (!\is_array($d) || ($d['prev'] ?? null) !== $prev) {
                return $i + 1;
            }
            $prev = hash('sha256', $line);
        }

        return null;
    }

    /**
     * The hash of the last line: the chain's head. A chain only proves that nothing was removed
     * or changed in the middle; someone who rewrites the whole file builds a new valid chain. Keep
     * the head somewhere else (an email, a ticket, another server): contains() then tells whether
     * the journal still holds that point.
     */
    public function head(): ?string
    {
        $lines = $this->lines();

        return [] === $lines ? null : hash('sha256', $lines[\count($lines) - 1]);
    }

    /** Whether a head noted earlier is still one of the lines of the chain. */
    public function contains(string $head): bool
    {
        foreach ($this->lines() as $line) {
            if (hash_equals(hash('sha256', $line), strtolower(trim($head)))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function lines(): array
    {
        return @file($this->file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
    }

    /** @param resource $h */
    private function lastHash($h): ?string
    {
        $size = fstat($h)['size'] ?? 0;
        if (0 === $size) {
            return null;
        }
        $chunk = min($size, 65536);
        fseek($h, -$chunk, \SEEK_END);
        $tail = rtrim((string) fread($h, $chunk), "\n");
        $pos = strrpos($tail, "\n");

        return hash('sha256', false === $pos ? $tail : substr($tail, $pos + 1));
    }
}

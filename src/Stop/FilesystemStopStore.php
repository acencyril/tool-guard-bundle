<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Stop;

/**
 * The stops in one JSON file (default var/tool_guard/stops.json, next to the approvals). A change
 * reads, edits and writes the file under an exclusive lock (two admins stopping two tools at the
 * same second keep both stops), and the write is a temporary file renamed over the old one, so a
 * reader never sees half a file. Readers take no lock: a rename is atomic.
 */
final class FilesystemStopStore implements StopStore
{
    public function __construct(private readonly string $file)
    {
    }

    public function add(Stop $stop): void
    {
        $this->change(static function (array $stops) use ($stop): array {
            $stops[$stop->key()] = $stop;

            return [$stops, null];
        });
    }

    public function remove(string $scope, ?string $target = null): ?Stop
    {
        $key = Stop::keyFor($scope, $target);

        return $this->change(static function (array $stops) use ($key): array {
            $removed = $stops[$key] ?? null;
            unset($stops[$key]);

            return [$stops, $removed];
        });
    }

    public function all(): array
    {
        return array_values($this->read());
    }

    /**
     * @param callable(array<string, Stop>): array{array<string, Stop>, ?Stop} $edit
     */
    private function change(callable $edit): ?Stop
    {
        $dir = \dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create "%s".', $dir));
        }
        $lock = fopen($this->file.'.lock', 'c');
        if (false === $lock) {
            throw new \RuntimeException(sprintf('Cannot open "%s.lock".', $this->file));
        }
        try {
            flock($lock, \LOCK_EX);
            [$stops, $out] = $edit($this->read());
            $tmp = tempnam($dir, '.stops');
            if (false === $tmp) {
                throw new \RuntimeException(sprintf('Cannot write in "%s".', $dir));
            }
            $json = json_encode(array_values(array_map(static fn (Stop $s) => $s->toArray(), $stops)), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            if (false === file_put_contents($tmp, $json) || !rename($tmp, $this->file)) {
                @unlink($tmp);
                throw new \RuntimeException(sprintf('Cannot write "%s".', $this->file));
            }

            return $out;
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, Stop> by key */
    private function read(): array
    {
        clearstatcache(true, $this->file);
        if (!is_file($this->file)) {
            return [];
        }
        $raw = @file_get_contents($this->file);
        $d = false === $raw ? null : json_decode($raw, true);
        if (!\is_array($d)) {
            // fail closed: the guard refuses every call rather than guess that nothing is stopped
            throw new \RuntimeException(sprintf('The stop state in "%s" cannot be read.', $this->file));
        }
        $stops = [];
        foreach ($d as $row) {
            $s = Stop::fromArray((array) $row);
            $stops[$s->key()] = $s;
        }

        return $stops;
    }
}

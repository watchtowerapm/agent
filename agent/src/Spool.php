<?php

namespace Watchtower\LaravelAgent;

use Watchtower\LaravelAgent\Contracts\Clock;

use function array_sum;
use function basename;
use function bin2hex;
use function count;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function glob;
use function is_dir;
use function microtime;
use function mkdir;
use function random_bytes;
use function rename;
use function sort;
use function sprintf;
use function strlen;
use function unlink;

/**
 * Keeps payloads the agent could not deliver (Watchtower unreachable, or busy) on disk so they
 * are sent later instead of lost. Bounded by size and age: when full, the oldest payloads are
 * dropped first. Each payload is one gzip file; names sort oldest first.
 */
class Spool
{
    public function __construct(
        private string $directory,
        private int $maxBytes,
        private int $maxAgeInSeconds,
        private Clock $clock,
    ) {
        //
    }

    /**
     * Store a payload. Returns false when it could not be written (e.g. disk unavailable).
     */
    public function put(string $payload): bool
    {
        if (strlen($payload) > $this->maxBytes) {
            return false;
        }

        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            return false;
        }

        // "<unix time>-<microtime>-<random>": sorts oldest first; the prefix gives the age.
        $name = sprintf('%010d-%017.6f-%s', $this->clock->time(), microtime(true), bin2hex(random_bytes(4)));
        $temporary = "{$this->directory}/.{$name}.tmp";

        if (@file_put_contents($temporary, $payload) === false || ! @rename($temporary, "{$this->directory}/{$name}.gz")) {
            @unlink($temporary);

            return false;
        }

        $this->prune();

        return true;
    }

    /**
     * The oldest stored payload, as [path, contents], or null when the spool is empty.
     *
     * @return array{0: string, 1: string}|null
     */
    public function oldest(): ?array
    {
        foreach ($this->files() as $path) {
            $contents = @file_get_contents($path);

            if ($contents !== false) {
                return [$path, $contents];
            }

            @unlink($path);
        }

        return null;
    }

    public function remove(string $path): void
    {
        @unlink($path);
    }

    public function isEmpty(): bool
    {
        return $this->files() === [];
    }

    public function count(): int
    {
        return count($this->files());
    }

    /**
     * Drop payloads older than the maximum age, then the oldest ones until under the size cap.
     */
    private function prune(): void
    {
        $files = $this->files();
        $cutoff = $this->clock->time() - $this->maxAgeInSeconds;
        $sizes = [];

        foreach ($files as $index => $path) {
            if ((int) basename($path) < $cutoff) {
                @unlink($path);
                unset($files[$index]);

                continue;
            }

            $sizes[$path] = (int) @filesize($path);
        }

        $total = array_sum($sizes);

        foreach ($sizes as $path => $size) {
            if ($total <= $this->maxBytes) {
                break;
            }

            @unlink($path);
            $total -= $size;
        }
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob("{$this->directory}/*.gz") ?: [];
        sort($files);

        return $files;
    }
}

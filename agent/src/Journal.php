<?php

namespace Watchtower\LaravelAgent;

use function bin2hex;
use function fclose;
use function fflush;
use function file;
use function flock;
use function fopen;
use function fwrite;
use function glob;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function mkdir;
use function random_bytes;
use function rename;
use function rmdir;
use function sort;
use function sprintf;
use function strlen;
use function substr;
use function unlink;

/**
 * Records batches on disk before the sidecar acknowledges them, so a crash can't lose data the
 * application was told was received. Each batch is appended as one line; when the in-memory
 * buffer is sent, the lines so far are sealed into a file and removed once that upload is done
 * (delivered, kept in the spool, or deliberately dropped). On start, journals left by a sidecar
 * that stopped uncleanly are recovered into the spool.
 *
 * Each sidecar process writes to its own directory and holds a lock on it while it runs, so
 * sidecars sharing a spool directory never recover each other's live journal.
 *
 * Lines are flushed to the operating system, not fsync'd: this survives a sidecar crash, not a
 * power loss.
 */
class Journal
{
    /** @var resource|null Held while this sidecar runs (see recover()). */
    private $lock = null; // @phpstan-ignore property.onlyWritten

    /** @var resource|null */
    private $current = null;

    private ?string $directory = null;

    private int $sealed = 0;

    public function __construct(private string $root)
    {
        //
    }

    /**
     * Append one batch (a JSON list). Returns false when it couldn't be written.
     */
    public function append(string $records): bool
    {
        $current = $this->current ?? $this->open();

        if ($current === null) {
            return false;
        }

        $line = $records."\n";

        return @fwrite($current, $line) === strlen($line) && @fflush($current);
    }

    /**
     * Close the lines written so far into a file of their own and return its path, or null
     * when nothing was written since the last seal.
     */
    public function seal(): ?string
    {
        if ($this->current === null || $this->directory === null) {
            return null;
        }

        @fclose($this->current);
        $this->current = null;

        $path = sprintf('%s/%08d.sealed', $this->directory, ++$this->sealed);

        return @rename("{$this->directory}/current", $path) ? $path : null;
    }

    public function release(?string $path): void
    {
        if ($path !== null) {
            @unlink($path);
        }
    }

    /**
     * Drop what was written since the last seal (ingestion paused: the buffer is dropped too).
     */
    public function discard(): void
    {
        $this->release($this->seal());
    }

    /**
     * Payloads, shaped like the ingest buffer's (`{"records":[...]}`), from journals of
     * sidecars that are no longer running. Their files are removed.
     *
     * @return list<string>
     */
    public function recover(): array
    {
        $payloads = [];

        foreach (glob("{$this->root}/journal-*", GLOB_ONLYDIR) ?: [] as $directory) {
            if ($directory === $this->directory) {
                continue;
            }

            $lock = @fopen("{$directory}/lock", 'c');

            if ($lock === false) {
                continue;
            }

            // Still held: that sidecar is running.
            if (! @flock($lock, LOCK_EX | LOCK_NB)) {
                @fclose($lock);

                continue;
            }

            $files = glob("{$directory}/*.sealed") ?: [];
            sort($files);

            if (is_file("{$directory}/current")) {
                $files[] = "{$directory}/current";
            }

            foreach ($files as $file) {
                $records = [];

                foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    // A crash mid-write leaves a partial last line; keep only complete lists.
                    if (is_array(json_decode($line, true)) && $line[0] === '[' && $line !== '[]') {
                        $records[] = substr($line, 1, -1);
                    }
                }

                if ($records !== []) {
                    $payloads[] = '{"records":['.implode(',', $records).']}';
                }

                @unlink($file);
            }

            @unlink("{$directory}/lock");
            @flock($lock, LOCK_UN);
            @fclose($lock);
            @rmdir($directory);
        }

        return $payloads;
    }

    /**
     * @return resource|null
     */
    private function open()
    {
        if ($this->directory === null) {
            $directory = "{$this->root}/journal-".bin2hex(random_bytes(6));

            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                return null;
            }

            $lock = @fopen("{$directory}/lock", 'c');

            if ($lock === false || ! @flock($lock, LOCK_EX | LOCK_NB)) {
                return null;
            }

            $this->lock = $lock;
            $this->directory = $directory;
        }

        $current = @fopen("{$this->directory}/current", 'ab');

        return $this->current = $current === false ? null : $current;
    }
}

<?php

namespace Watchtower\LaravelAgent;

use function strlen;
use function strpos;
use function substr;

/**
 * Incremental parser for Watchtower length-prefixed JSON frames.
 *
 * @internal
 */
final class FrameBuffer
{
    private string $buffer = '';

    /**
     * @var list<array<string, mixed>>
     */
    private array $messages = [];

    public function append(string $chunk): void
    {
        $this->buffer .= $chunk;
        $this->drain();
    }

    public function shift(): ?array
    {
        if ($this->messages === []) {
            return null;
        }

        return array_shift($this->messages);
    }

    public function isEmpty(): bool
    {
        return $this->buffer === '' && $this->messages === [];
    }

    public function leftover(): string
    {
        return $this->buffer;
    }

    private function drain(): void
    {
        while (true) {
            $newline = strpos($this->buffer, "\n");

            if ($newline === false) {
                return;
            }

            $size = (int) substr($this->buffer, 0, $newline);
            $start = $newline + 1;
            $end = $start + $size;

            if ($size < 1 || strlen($this->buffer) < $end) {
                return;
            }

            try {
                [$message, $consumed] = Frame::decodeOne($this->buffer, 0);
            } catch (\Throwable) {
                return;
            }

            $this->messages[] = $message;
            $this->buffer = substr($this->buffer, $consumed);
        }
    }
}

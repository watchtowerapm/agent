<?php

namespace Watchtower\LaravelAgent;

use UnexpectedValueException;

use function array_shift;
use function substr;

/**
 * Incremental parser for Watchtower length-prefixed JSON frames.
 *
 * Once the stream contains bytes that can never form a valid frame, the buffer stops parsing
 * and reports the reason through error(); the server then rejects the connection.
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

    private ?string $error = null;

    public function append(string $chunk): void
    {
        if ($this->error !== null) {
            return;
        }

        $this->buffer .= $chunk;
        $this->drain();
    }

    /**
     * @return array<string, mixed>|null
     */
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

    public function error(): ?string
    {
        return $this->error;
    }

    private function drain(): void
    {
        while ($this->buffer !== '') {
            try {
                $decoded = Frame::tryDecodeOne($this->buffer);
            } catch (UnexpectedValueException $e) {
                $this->error = $e->getMessage();

                return;
            }

            if ($decoded === null) {
                return;
            }

            [$message, $consumed] = $decoded;
            $this->messages[] = $message;
            $this->buffer = substr($this->buffer, $consumed);
        }
    }
}

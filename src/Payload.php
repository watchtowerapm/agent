<?php

namespace Watchtower\Laravel;

use RuntimeException;
use Watchtower\Laravel\Transport\Frame;
use Watchtower\Laravel\Transport\Protocol;

use function in_array;
use function json_decode;
use function json_encode;

/**
 * Record list destined for a Watchtower telemetry_batch frame.
 *
 * @internal
 */
final class Payload
{
    public const PROTOCOL = Protocol::NAME;

    public const PROTOCOL_VERSION = Protocol::VERSION;

    public const BATCH_VERSION = Protocol::BATCH_VERSION;

    private bool $pulled = false;

    /**
     * @param  'TEXT'|'JSON'  $type
     */
    public function __construct(
        private string $type,
        private string $payload,
        private string $tokenHash,
    ) {
        //
    }

    public static function text(string $payload, string $tokenHash): self
    {
        return new self('TEXT', $payload, $tokenHash);
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     */
    public static function json(array $payload, string $tokenHash): self
    {
        return new self(
            'JSON',
            json_encode($payload, flags: JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $tokenHash
        );
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function pullAs(array $message): string
    {
        if ($this->pulled) {
            throw new RuntimeException('Payload has already been read');
        }

        $this->pulled = true;
        $this->payload = '';

        return Frame::encode($message);
    }

    public function rawPayload(): string
    {
        return $this->payload;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function isPing(): bool
    {
        return $this->type === 'TEXT' && $this->payload === 'PING';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function records(): array
    {
        if ($this->type !== 'JSON') {
            return [];
        }

        /** @var list<array<string, mixed>> */
        return json_decode($this->payload, true) ?? [];
    }

    public function isEmpty(): bool
    {
        return match ($this->type) {
            'JSON' => in_array($this->payload, ['[]', '{}', '""', 'null'], true),
            'TEXT' => $this->payload === '',
        };
    }
}

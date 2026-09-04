<?php

namespace Watchtower\Laravel;

use Deprecated;
use Illuminate\Contracts\Events\Dispatcher;
use RuntimeException;
use Watchtower\Laravel\Events\IngestingEvents;
use Watchtower\Laravel\Transport\Frame;
use Watchtower\Laravel\Transport\Protocol;

use function call_user_func;
use function json_encode;
use function substr;
use function WatchtowerAgent\fclose_safely;
use function WatchtowerAgent\fread_chunk;
use function WatchtowerAgent\fwrite_all;
use function WatchtowerAgent\stream_configure_read_timeout;

/**
 * @internal
 */
final class Ingest implements \Watchtower\Laravel\Contracts\Ingest
{
    private string $transmitTo;

    private bool $shouldDigestWhenBufferIsFull = true;

    private bool $ingesting = false;

    private int $sequence = 0;

    /**
     * @param  (callable(string $address, float $timeout): resource)  $streamFactory
     */
    public function __construct(
        string $transmitTo,
        private float $connectionTimeout,
        private float $timeout,
        public $streamFactory,
        public RecordsBuffer $buffer,
        private string $tokenHash,
        private Dispatcher $events,
        private string $agentVersion,
    ) {
        $this->transmitTo = "tcp://{$transmitTo}";
    }

    public function write(array $record): void
    {
        if ($this->ingesting) {
            return;
        }

        $this->buffer->write($record);

        if ($this->shouldDigestWhenBufferIsFull && $this->buffer->full) {
            $this->digest();
        }
    }

    public function writeNow(array $record): void
    {
        if ($this->ingesting) {
            return;
        }

        if (! $this->dispatchIngestingEvents([$record])) {
            return;
        }

        $this->transmit(Payload::json([$record], $this->tokenHash));
    }

    public function flush(): void
    {
        $this->buffer->flush();
    }

    public function ping(): void
    {
        $this->transmit(Payload::text('PING', $this->tokenHash));
    }

    #[Deprecated('Use shouldDigestWhenBufferIsFull instead')]
    public function shouldDigest(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull($bool);
    }

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull = $bool;
    }

    public function digest(): void
    {
        if ($this->buffer->count() === 0) {
            return;
        }

        if (! $this->dispatchIngestingEvents($this->buffer->all())) {
            $this->buffer->flush();

            return;
        }

        $this->transmit($this->buffer->pull($this->tokenHash));
    }

    /**
     * @param  list<array<mixed>>  $records
     */
    private function dispatchIngestingEvents(array $records): bool
    {
        if ($records === []) {
            return true;
        }

        $this->ingesting = true;

        try {
            return $this->events->until(new IngestingEvents($records)) !== false;
        } finally {
            $this->ingesting = false;
        }
    }

    private function transmit(Payload $payload): void
    {
        $stream = $this->createStream();

        try {
            $this->configureStreamTimeout($stream);

            fwrite_all($stream, Frame::encode($this->hello()));

            $carry = '';
            $welcome = $this->readMessage($stream, $carry);

            if (($welcome['type'] ?? null) !== Protocol::TYPE_WELCOME || ($welcome['accepted'] ?? false) !== true) {
                throw new RuntimeException('Unexpected response from agent ['.json_encode($welcome).']');
            }

            $sessionId = (string) ($welcome['session_id'] ?? '');
            $sequence = ++$this->sequence;

            fwrite_all($stream, Frame::encode($this->command($payload, $sessionId, $sequence)));

            $ack = $this->readMessage($stream, $carry);

            if (($ack['type'] ?? null) !== Protocol::TYPE_ACK) {
                throw new RuntimeException('Unexpected response from agent ['.json_encode($ack).']');
            }
        } finally {
            fclose_safely($stream);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function hello(): array
    {
        return [
            'type' => Protocol::TYPE_HELLO,
            'protocol' => Protocol::NAME,
            'protocol_version' => Protocol::VERSION,
            'agent_version' => $this->agentVersion,
            'sdk' => 'laravel',
            'php_version' => PHP_VERSION,
            'token_hash' => $this->tokenHash,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function command(Payload $payload, string $sessionId, int $sequence): array
    {
        if ($payload->isPing()) {
            return [
                'type' => Protocol::TYPE_PING,
                'protocol_version' => Protocol::VERSION,
                'session_id' => $sessionId,
                'sequence' => $sequence,
            ];
        }

        return [
            'type' => Protocol::TYPE_TELEMETRY_BATCH,
            'protocol' => Protocol::NAME,
            'protocol_version' => Protocol::VERSION,
            'batch_version' => Protocol::BATCH_VERSION,
            'session_id' => $sessionId,
            'sequence' => $sequence,
            'records' => $payload->records(),
        ];
    }

    /**
     * @param  resource  $stream
     * @return array<string, mixed>
     */
    private function readMessage($stream, string &$carry): array
    {
        for ($i = 0; $i < 8192; $i++) {
            if ($carry !== '') {
                try {
                    [$message, $end] = Frame::decodeOne($carry, 0);
                    $carry = substr($carry, $end);

                    return $message;
                } catch (RuntimeException|\JsonException) {
                    // Need more bytes.
                }
            }

            $chunk = fread_chunk($stream, 8192);

            if ($chunk === '') {
                throw new RuntimeException("Unexpected response from agent [{$carry}]");
            }

            $carry .= $chunk;
        }

        throw new RuntimeException("Unexpected response from agent [{$carry}]");
    }

    /**
     * @return resource
     */
    private function createStream()
    {
        return call_user_func($this->streamFactory, $this->transmitTo, $this->connectionTimeout);
    }

    /**
     * @param  resource  $stream
     */
    private function configureStreamTimeout($stream): void
    {
        stream_configure_read_timeout($stream, $this->timeout);
    }
}

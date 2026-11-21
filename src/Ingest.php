<?php

namespace Watchtower\Laravel;

use Deprecated;
use Illuminate\Contracts\Events\Dispatcher;
use RuntimeException;
use Throwable;
use UnexpectedValueException;
use Watchtower\Laravel\Events\IngestingEvents;
use Watchtower\Laravel\Facades\Watchtower;
use Watchtower\Laravel\Transport\Frame;
use Watchtower\Laravel\Transport\Mac;
use Watchtower\Laravel\Transport\Protocol;

use function array_slice;
use function call_user_func;
use function count;
use function is_int;
use function is_string;
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

    /**
     * @var callable(): string
     */
    public $nonceFactory;

    /**
     * @param  (callable(string $address, float $timeout): resource)  $streamFactory
     * @param  string  $key  the session key derived from the full token (see Mac::key())
     * @param  string  $tokenHash  sent only so a sidecar still running protocol 1 recognises an upgraded app and restarts
     * @param  (callable(): string)|null  $nonceFactory
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
        private string $key,
        ?callable $nonceFactory = null,
    ) {
        $this->transmitTo = "tcp://{$transmitTo}";
        $this->nonceFactory = $nonceFactory ?? Mac::nonce(...);
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

        try {
            if (! $this->dispatchIngestingEvents([$record])) {
                return;
            }

            $this->transmitRecords([$record]);
        } catch (Throwable $e) {
            Watchtower::unrecoverableExceptionOccurred($e);
        }
    }

    public function flush(): void
    {
        $this->buffer->flush();
    }

    public function ping(): void
    {
        $this->session(function ($stream, string $sessionId, int $sequence, string &$carry, int $maxBatch, callable $sign): void {
            fwrite_all($stream, Frame::encode([
                'type' => Protocol::TYPE_PING,
                'protocol_version' => Protocol::VERSION,
                'session_id' => $sessionId,
                'sequence' => $sequence,
                'mac' => $sign(Protocol::TYPE_PING, $sequence, ''),
            ]));

            $this->assertAck($this->readFrame($stream, $carry), $sequence);
        });
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

        try {
            if (! $this->dispatchIngestingEvents($this->buffer->all())) {
                $this->buffer->flush();

                return;
            }

            $this->transmitRecords($this->buffer->pull());
        } catch (Throwable $e) {
            Watchtower::unrecoverableExceptionOccurred($e);
        }
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

    /**
     * Send records over as many sessions as needed. Each session carries one batch of at most
     * the `max_batch` the sidecar announced in WELCOME, so a large buffer is split, not dropped.
     *
     * @param  list<array<mixed>>  $records
     */
    private function transmitRecords(array $records): void
    {
        $offset = 0;
        $total = count($records);

        while ($offset < $total) {
            $this->session(function ($stream, string $sessionId, int $sequence, string &$carry, int $maxBatch, callable $sign) use ($records, &$offset): void {
                $batch = array_slice($records, $offset, $maxBatch);

                $this->transmitBatch($stream, $sessionId, $sequence, $carry, $batch, $sign);

                $offset += count($batch);
            });
        }
    }

    /**
     * The records are encoded once and spliced into the frame as its last member, so the
     * signature covers those exact bytes and the sidecar forwards them without re-encoding.
     *
     * @param  resource  $stream
     * @param  list<array<mixed>>  $records
     * @param  callable(string, int, string): string  $sign
     */
    private function transmitBatch($stream, string $sessionId, int $sequence, string &$carry, array $records, callable $sign): void
    {
        $json = Frame::json($records);

        fwrite_all($stream, Frame::encodeWithRawMember([
            'type' => Protocol::TYPE_TELEMETRY_BATCH,
            'protocol' => Protocol::NAME,
            'protocol_version' => Protocol::VERSION,
            'batch_version' => Protocol::BATCH_VERSION,
            'session_id' => $sessionId,
            'sequence' => $sequence,
            'mac' => $sign(Protocol::TYPE_TELEMETRY_BATCH, $sequence, $json),
        ], 'records', $json));

        $this->assertAck($this->readFrame($stream, $carry), $sequence);
    }

    /**
     * @param  callable(resource, string, int, string, int, callable(string, int, string): string): void  $then
     */
    private function session(callable $then): void
    {
        $stream = $this->createStream();

        try {
            $this->configureStreamTimeout($stream);

            $clientNonce = (string) call_user_func($this->nonceFactory);

            fwrite_all($stream, Frame::encode([
                'type' => Protocol::TYPE_HELLO,
                'protocol' => Protocol::NAME,
                'protocol_version' => Protocol::VERSION,
                'agent_version' => $this->agentVersion,
                'sdk' => 'laravel',
                'php_version' => PHP_VERSION,
                'token_hash' => $this->tokenHash,
                'nonce' => $clientNonce,
                'mac' => Mac::hello($this->key, Protocol::VERSION, $clientNonce),
            ]));

            $carry = '';
            $welcome = $this->readFrame($stream, $carry);

            $this->throwIfRejected($welcome);

            if (($welcome['type'] ?? null) !== Protocol::TYPE_WELCOME || ($welcome['accepted'] ?? false) !== true) {
                throw new RuntimeException('Unexpected response from agent ['.json_encode($welcome).']');
            }

            $sessionId = $welcome['session_id'] ?? '';
            $sessionId = is_string($sessionId) ? $sessionId : '';

            if ($sessionId === '') {
                throw new RuntimeException('Unexpected response from agent ['.json_encode($welcome).']');
            }

            $serverNonce = $welcome['nonce'] ?? '';
            $serverNonce = is_string($serverNonce) ? $serverNonce : '';

            // The sidecar proves it holds the same token before it gets any data.
            if ($serverNonce === '' || ! Mac::matches(Mac::welcome($this->key, $clientNonce, $sessionId, $serverNonce), $welcome['mac'] ?? null)) {
                throw new RuntimeException("The Watchtower agent at [{$this->transmitTo}] could not prove it uses the same WATCHTOWER_TOKEN as this application");
            }

            $maxBatch = $welcome['max_batch'] ?? Protocol::MAX_BATCH;
            $maxBatch = is_int($maxBatch) && $maxBatch > 0 ? $maxBatch : Protocol::MAX_BATCH;

            $sign = fn (string $type, int $sequence, string $body): string => Mac::command($this->key, $type, $sessionId, $sequence, $clientNonce, $serverNonce, $body);

            $then($stream, $sessionId, 1, $carry, $maxBatch, $sign);
        } finally {
            fclose_safely($stream);
        }
    }

    /**
     * @param  array<string, mixed>  $ack
     */
    private function assertAck(array $ack, int $sequence): void
    {
        $this->throwIfRejected($ack);

        if (
            ($ack['type'] ?? null) !== Protocol::TYPE_ACK
            || ($ack['sequence'] ?? null) !== $sequence
            || ($ack['rejected'] ?? null) !== 0
        ) {
            throw new RuntimeException('Unexpected response from agent ['.json_encode($ack).']');
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function throwIfRejected(array $message): void
    {
        if (($message['type'] ?? null) !== Protocol::TYPE_ERROR) {
            return;
        }

        $code = $message['code'] ?? 'unknown';
        $code = is_string($code) ? $code : 'unknown';

        throw new RuntimeException("The Watchtower agent rejected the connection [{$code}]");
    }

    /**
     * @param  resource  $stream
     * @return array<string, mixed>
     */
    private function readFrame($stream, string &$carry): array
    {
        for ($i = 0; $i < 8192; $i++) {
            if ($carry !== '') {
                try {
                    $decoded = Frame::tryDecodeOne($carry);
                } catch (UnexpectedValueException $e) {
                    throw new RuntimeException("Unexpected response from agent [{$carry}]", previous: $e);
                }

                if ($decoded !== null) {
                    [$message, $end] = $decoded;
                    $carry = substr($carry, $end);

                    return $message;
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

<?php

namespace Watchtower\LaravelAgent;

use Closure;
use React\Socket\ConnectionInterface;
use React\Socket\ServerInterface;
use Throwable;

use function bin2hex;
use function call_user_func;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;
use function random_bytes;

class Server
{
    /**
     * @param  (Closure(): ServerInterface)  $serverResolver
     * @param  (Closure(): mixed)  $onServerStarted
     * @param  (Closure(string $message): mixed)  $onServerError
     * @param  (Closure(string $message): mixed)  $onConnectionError
     * @param  (Closure(string $payload): mixed)  $onPayloadReceived
     * @param  (Closure(): mixed)  $onInvalidPayloadVersion
     * @param  (Closure(): mixed)  $onInvalidTokenHash
     */
    public function __construct(
        private Closure $serverResolver,
        private string $tokenHash,
        private Closure $onServerStarted,
        private Closure $onServerError,
        private Closure $onConnectionError,
        private Closure $onPayloadReceived,
        private Closure $onInvalidPayloadVersion,
        private Closure $onInvalidTokenHash,
    ) {
        //
    }

    public function start(): void
    {
        $server = call_user_func($this->serverResolver);

        $server->on('connection', function (ConnectionInterface $connection) use ($server): void {
            $frames = new FrameBuffer;
            $sessionId = null;
            $expectedSequence = 1;
            $recordsJson = null;
            $closedByUs = false;

            $connection->on('data', function (string $chunk) use ($connection, $server, $frames, &$sessionId, &$expectedSequence, &$recordsJson, &$closedByUs): void {
                $frames->append($chunk);

                while ($message = $frames->shift()) {
                    $type = $message['type'] ?? null;

                    if ($sessionId === null) {
                        if ($type !== Protocol::TYPE_HELLO) {
                            $this->reject($connection, 'expected_hello');
                            $closedByUs = true;

                            return;
                        }

                        if (! $this->protocolIsValid($message)) {
                            $this->reject($connection, 'unsupported_protocol');
                            $closedByUs = true;
                            $server->close();
                            call_user_func($this->onInvalidPayloadVersion);

                            return;
                        }

                        if (($message['token_hash'] ?? '') !== $this->tokenHash) {
                            $this->reject($connection, 'token_mismatch');
                            $closedByUs = true;
                            call_user_func($this->onInvalidTokenHash);

                            return;
                        }

                        $sessionId = 'sess_'.bin2hex(random_bytes(8));
                        $connection->write(Frame::encode([
                            'type' => Protocol::TYPE_WELCOME,
                            'accepted' => true,
                            'protocol_version' => Protocol::VERSION,
                            'session_id' => $sessionId,
                            'max_batch' => Protocol::MAX_BATCH,
                        ]));

                        continue;
                    }

                    if ($type !== Protocol::TYPE_PING && $type !== Protocol::TYPE_TELEMETRY_BATCH) {
                        $this->reject($connection, 'unexpected_type');
                        $closedByUs = true;

                        return;
                    }

                    if ($this->stringField($message, 'session_id') !== $sessionId) {
                        $this->reject($connection, 'session_mismatch');
                        $closedByUs = true;

                        return;
                    }

                    if ($this->intField($message, 'sequence') !== $expectedSequence) {
                        $this->reject($connection, 'sequence_mismatch');
                        $closedByUs = true;

                        return;
                    }

                    if ($type === Protocol::TYPE_PING) {
                        $this->ack($connection, $expectedSequence, 0);
                        $closedByUs = true;

                        return;
                    }

                    if ($this->intField($message, 'batch_version') !== Protocol::BATCH_VERSION) {
                        $this->reject($connection, 'unsupported_batch_version');
                        $closedByUs = true;

                        return;
                    }

                    $records = $message['records'] ?? [];

                    if (! is_array($records) || count($records) > Protocol::MAX_BATCH) {
                        $this->reject($connection, 'batch_too_large');
                        $closedByUs = true;

                        return;
                    }

                    $recordsJson = json_encode($records, flags: JSON_THROW_ON_ERROR);
                    $this->ack($connection, $expectedSequence, count($records));
                    $closedByUs = true;

                    return;
                }
            });

            $connection->on('close', function () use ($frames, &$recordsJson, &$closedByUs): void {
                if (! $closedByUs && ! $frames->isEmpty()) {
                    call_user_func($this->onConnectionError, "Incomplete payload received. Buffer: [{$frames->leftover()}]");

                    return;
                }

                if (is_string($recordsJson)) {
                    call_user_func($this->onPayloadReceived, $recordsJson);
                }
            });

            $connection->on('error', function (Throwable $e): void {
                call_user_func($this->onConnectionError, $e->getMessage());
            });
        });

        $server->on('error', function (Throwable $e): void {
            call_user_func($this->onServerError, $e);
        });

        call_user_func($this->onServerStarted);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function protocolIsValid(array $message): bool
    {
        return ($message['protocol'] ?? null) === Protocol::NAME
            && $this->intField($message, 'protocol_version') === Protocol::VERSION;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function intField(array $message, string $key): int
    {
        $value = $message[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function stringField(array $message, string $key): string
    {
        $value = $message[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function ack(ConnectionInterface $connection, int $sequence, int $accepted): void
    {
        $connection->end(Frame::encode([
            'type' => Protocol::TYPE_ACK,
            'sequence' => $sequence,
            'accepted' => $accepted,
            'rejected' => 0,
        ]));
    }

    private function reject(ConnectionInterface $connection, string $code): void
    {
        $connection->end(Frame::encode([
            'type' => Protocol::TYPE_ERROR,
            'accepted' => false,
            'code' => $code,
        ]));
    }
}

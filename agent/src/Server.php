<?php

namespace Watchtower\LaravelAgent;

use Closure;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;
use React\Socket\ServerInterface;
use Throwable;

use function array_is_list;
use function bin2hex;
use function call_user_func;
use function count;
use function hash_equals;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;
use function random_bytes;

/**
 * Local TCP endpoint the Laravel package talks to. Each connection is one exchange:
 * HELLO → WELCOME, then one PING or TELEMETRY_BATCH → ACK, then the sidecar closes it.
 *
 * Checks run in this order so unauthenticated clients learn as little as possible and can
 * never stop the sidecar: frame validity, HELLO first, token, protocol, then the command.
 */
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
        private ?LoopInterface $loop = null,
        private float $idleTimeout = 0.0,
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
            $finished = false;
            $idleTimer = $this->startIdleTimer($connection, $finished);

            $finish = function () use (&$finished, &$idleTimer): void {
                $finished = true;

                if ($idleTimer !== null) {
                    $this->loop?->cancelTimer($idleTimer);
                    $idleTimer = null;
                }
            };

            $connection->on('data', function (string $chunk) use ($connection, $server, $frames, &$sessionId, &$expectedSequence, &$recordsJson, &$finished, $finish): void {
                if ($finished) {
                    return;
                }

                $frames->append($chunk);

                if ($frames->error() !== null) {
                    $this->reject($connection, Protocol::ERROR_INVALID_FRAME);
                    $finish();
                    call_user_func($this->onConnectionError, "Invalid frame received: {$frames->error()}");

                    return;
                }

                while (($message = $frames->shift()) !== null) {
                    $type = $message['type'] ?? null;

                    if ($sessionId === null) {
                        if ($type !== Protocol::TYPE_HELLO) {
                            $this->reject($connection, Protocol::ERROR_EXPECTED_HELLO);
                            $finish();

                            return;
                        }

                        $tokenHash = $this->stringField($message, 'token_hash');

                        if ($tokenHash === '' || ! hash_equals($this->tokenHash, $tokenHash)) {
                            $this->reject($connection, Protocol::ERROR_TOKEN_MISMATCH);
                            $finish();
                            call_user_func($this->onInvalidTokenHash);

                            return;
                        }

                        if (! $this->protocolIsValid($message)) {
                            $this->reject($connection, Protocol::ERROR_UNSUPPORTED_PROTOCOL);
                            $finish();
                            $server->close();
                            call_user_func($this->onInvalidPayloadVersion);

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
                        $this->reject($connection, Protocol::ERROR_UNEXPECTED_TYPE);
                        $finish();

                        return;
                    }

                    if (! hash_equals($sessionId, $this->stringField($message, 'session_id'))) {
                        $this->reject($connection, Protocol::ERROR_SESSION_MISMATCH);
                        $finish();

                        return;
                    }

                    if ($this->intField($message, 'sequence') !== $expectedSequence) {
                        $this->reject($connection, Protocol::ERROR_SEQUENCE_MISMATCH);
                        $finish();

                        return;
                    }

                    if ($type === Protocol::TYPE_PING) {
                        $this->ack($connection, $expectedSequence, 0);
                        $finish();

                        return;
                    }

                    if ($this->intField($message, 'batch_version') !== Protocol::BATCH_VERSION) {
                        $this->reject($connection, Protocol::ERROR_UNSUPPORTED_BATCH_VERSION);
                        $finish();

                        return;
                    }

                    $records = $message['records'] ?? null;

                    if (! is_array($records) || ! array_is_list($records)) {
                        $this->reject($connection, Protocol::ERROR_INVALID_RECORDS);
                        $finish();

                        return;
                    }

                    if (count($records) > Protocol::MAX_BATCH) {
                        $this->reject($connection, Protocol::ERROR_BATCH_TOO_LARGE);
                        $finish();

                        return;
                    }

                    $recordsJson = $records === [] ? null : json_encode($records, flags: JSON_THROW_ON_ERROR);
                    $this->ack($connection, $expectedSequence, count($records));
                    $finish();

                    return;
                }
            });

            $connection->on('close', function () use ($frames, &$recordsJson, &$finished, $finish): void {
                $completed = $finished;
                $finish();

                if (! $completed && ! $frames->isEmpty()) {
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
            call_user_func($this->onServerError, $e->getMessage());
        });

        call_user_func($this->onServerStarted);
    }

    /**
     * Close connections that never complete their exchange, so a stuck or slow client
     * cannot hold sockets open forever.
     */
    private function startIdleTimer(ConnectionInterface $connection, bool &$finished): ?TimerInterface
    {
        if ($this->loop === null || $this->idleTimeout <= 0) {
            return null;
        }

        return $this->loop->addTimer($this->idleTimeout, function () use ($connection, &$finished): void {
            if ($finished) {
                return;
            }

            $finished = true;
            $this->reject($connection, Protocol::ERROR_IDLE_TIMEOUT);
            call_user_func($this->onConnectionError, "Connection idle for {$this->idleTimeout}s; closed.");
        });
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

<?php

namespace Watchtower\LaravelAgent;

use Closure;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Http\Message\ResponseException;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;
use Watchtower\LaravelAgent\Contracts\Browser;
use Watchtower\LaravelAgent\Contracts\Clock;

use function array_key_last;
use function call_user_func;
use function count;
use function gzencode;
use function in_array;
use function json_decode;
use function microtime;
use function min;
use function React\Promise\all;
use function str_contains;
use function strlen;
use function substr;

class Ingest
{
    /**
     * @var array<int<0, max>, PromiseInterface<null>>
     */
    private array $concurrentRequests = [];

    private ?TimerInterface $digestDelayTimer = null;

    private StreamBuffer|NullBuffer $buffer;

    private StreamBuffer $streamBufferBackup;

    private ?TimerInterface $drainTimer = null;

    private bool $draining = false;

    private bool $paused = false;

    /** Seconds before the next attempt to resend spooled payloads; grows while they fail. */
    private float $drainBackoff = self::DRAIN_MIN_BACKOFF;

    private const DRAIN_MIN_BACKOFF = 5.0;

    private const DRAIN_MAX_BACKOFF = 60.0;

    /**
     * @param  (Closure(ResponseInterface $response, float $duration): mixed)  $onIngestSuccess
     * @param  (Closure(string $message, float $duration): mixed)  $onIngestError
     * @param  (Closure(string $message, float $duration): mixed)  $onOverQuota
     */
    public function __construct(
        private LoopInterface $loop,
        private Browser $browser,
        private IngestDetailsRepository $ingestDetails,
        private Clock $clock,
        StreamBuffer $buffer,
        private int $concurrentRequestLimit,
        private int $maxBufferDurationInSeconds,
        private Closure $onIngestSuccess,
        private Closure $onIngestError,
        private Closure $onOverQuota,
        private ?Spool $spool = null,
        private ?Closure $onSpooled = null,
    ) {
        $this->buffer = $this->streamBufferBackup = $buffer;

        // Payloads kept by a previous run are sent once the agent is up.
        if ($this->spool !== null && ! $this->spool->isEmpty()) {
            $this->scheduleDrain(self::DRAIN_MIN_BACKOFF);
        }
    }

    public function write(string $payload): void
    {
        if ($this->buffer->isNotEmpty() && $this->buffer->willExceedThresholdWith($payload)) {
            $this->cancelDigestDelay();

            $this->digest();
        }

        $this->buffer->write($payload);

        // Exceptions skip the batching delay so they reach the dashboard right away. Records
        // arrive as compact JSON, so an exception record always contains this exact marker
        // (the same text inside a string value would be escaped).
        if (str_contains($payload, '"t":"exception"')) {
            $this->cancelDigestDelay();

            $this->digest();

            return;
        }

        if ($this->buffer->reachedThreshold()) {
            $this->cancelDigestDelay();

            $this->digest();
        } elseif ($this->buffer->isNotEmpty()) {
            $this->digestDelayTimer ??= $this->loop->addTimer($this->maxBufferDurationInSeconds, function () {
                $this->digestDelayTimer = null;

                $this->digest();
            });
        }
    }

    /**
     * @return PromiseInterface<null>
     */
    public function forceDigest(): PromiseInterface
    {
        $this->cancelDigestDelay();

        if ($this->buffer->isNotEmpty()) {
            $this->digest();
        }

        return all($this->concurrentRequests)->then(static fn () => null);
    }

    public function pauseIngestion(): void
    {
        $this->paused = true;
        $this->buffer = new NullBuffer;

        $this->streamBufferBackup->flush();
    }

    public function resumeIngestion(): void
    {
        $this->paused = false;
        $this->buffer = $this->streamBufferBackup;

        if ($this->spool !== null && ! $this->spool->isEmpty()) {
            $this->scheduleDrain(0);
        }
    }

    private function digest(): void
    {
        $payload = $this->buffer->pull();

        if (count($this->concurrentRequests) >= $this->concurrentRequestLimit) {
            $compressed = $this->spool !== null ? gzencode($payload) : false;

            if ($compressed !== false && $this->keep($compressed)) {
                if ($this->onSpooled !== null) {
                    call_user_func($this->onSpooled, $this->spool->count());
                }

                return;
            }

            call_user_func($this->onIngestError, "Exceeded concurrent request limit. [{$this->concurrentRequestLimit}] requests are processing", 0.0);

            return;
        }

        // TODO determine what level is optimal here
        $payload = gzencode($payload);

        if ($payload === false) {
            call_user_func($this->onIngestError, 'Unable to compress payload.', 0.0);

            return;
        }

        $start = microtime(true);
        $currentRequestKey = (array_key_last($this->concurrentRequests) ?? -1) + 1;

        ($this->concurrentRequests[$currentRequestKey] = $this->ingestDetails->get()->then(function (?IngestDetails $ingestDetails) use ($payload, &$start): PromiseInterface {
            $start = microtime(true);

            if ($ingestDetails === null) {
                throw new RuntimeException('No authentication details');
            }

            if ($this->clock->time() > $ingestDetails->expiresAt) {
                throw new RuntimeException('Authentication token expired');
            }

            return $this->send($ingestDetails, $payload);
        })->then(function (ResponseInterface $response) use (&$start): null {
            $duration = microtime(true) - $start;

            [$message, $stop, $refreshIn] = $this->parseResponse($response);

            if ($stop) {
                $this->stop($this->onOverQuota, $duration, $message, $refreshIn);
            } else {
                call_user_func($this->onIngestSuccess, $response, microtime(true) - $start);

                // Watchtower is reachable again: send anything kept while it wasn't.
                if ($this->spool !== null && ! $this->draining && ! $this->spool->isEmpty()) {
                    $this->scheduleDrain(0);
                }
            }

            return null;
        })->catch(function (Throwable $e) use (&$start, $payload): null {
            $duration = microtime(true) - $start;

            [$message, $stop, $refreshIn] = $this->parseException($e);

            if (! $stop && $e instanceof ResponseException && $e->getResponse()->getStatusCode() === 401) {
                $this->ingestDetails->invalidate();
            }

            if ($e instanceof IngestUnreachableException) {
                $this->ingestDetails->invalidateUnreachable();
            }

            if ($stop) {
                $this->stop($this->onIngestError, $duration, $message, $refreshIn);
            } else {
                $kept = self::isRetryable($e) && $this->keep($payload);

                call_user_func($this->onIngestError, $kept ? "{$message}; kept to send later [{$this->spool?->count()} waiting]" : $message, $duration);
            }

            return null;
        }))->finally(function () use ($currentRequestKey): void {
            unset($this->concurrentRequests[$currentRequestKey]);
        });
    }

    /**
     * Keep a compressed payload in the spool to send later. Returns false without a spool.
     */
    private function keep(string $payload): bool
    {
        if ($this->spool === null || ! $this->spool->put($payload)) {
            return false;
        }

        if (! $this->draining) {
            $this->scheduleDrain($this->drainBackoff);
        }

        return true;
    }

    private function scheduleDrain(float $delay): void
    {
        if ($this->drainTimer !== null) {
            return;
        }

        $this->drainTimer = $this->loop->addTimer($delay, function () {
            $this->drainTimer = null;

            $this->drain();
        });
    }

    /**
     * Resend spooled payloads one at a time, oldest first. Stops at the first failure and
     * tries again later, waiting longer each time while Watchtower stays unreachable.
     */
    private function drain(): void
    {
        if ($this->spool === null || $this->draining || $this->paused) {
            return;
        }

        $next = $this->spool->oldest();

        if ($next === null) {
            $this->drainBackoff = self::DRAIN_MIN_BACKOFF;

            return;
        }

        [$path, $payload] = $next;
        $this->draining = true;
        $start = microtime(true);

        $this->ingestDetails->get()->then(function (?IngestDetails $ingestDetails) use ($payload, &$start): PromiseInterface {
            $start = microtime(true);

            if ($ingestDetails === null) {
                throw new RuntimeException('No authentication details');
            }

            if ($this->clock->time() > $ingestDetails->expiresAt) {
                throw new RuntimeException('Authentication token expired');
            }

            return $this->send($ingestDetails, $payload);
        })->then(function (ResponseInterface $response) use ($path, &$start): null {
            [$message, $stop, $refreshIn] = $this->parseResponse($response);
            $this->draining = false;

            if ($stop) {
                // Over quota: keep the payload; ingestion resumes (and drains) once allowed.
                $this->stop($this->onOverQuota, microtime(true) - $start, $message, $refreshIn);

                return null;
            }

            $this->spool?->remove($path);
            $this->drainBackoff = self::DRAIN_MIN_BACKOFF;
            call_user_func($this->onIngestSuccess, $response, microtime(true) - $start);
            $this->scheduleDrain(0);

            return null;
        })->catch(function (Throwable $e) use ($path, &$start): null {
            $this->draining = false;
            [$message, $stop, $refreshIn] = $this->parseException($e);

            if (! $stop && $e instanceof ResponseException && $e->getResponse()->getStatusCode() === 401) {
                $this->ingestDetails->invalidate();
            }

            if ($e instanceof IngestUnreachableException) {
                $this->ingestDetails->invalidateUnreachable();
            }

            if (! $stop && ! self::isRetryable($e)) {
                // Watchtower rejected this payload itself; resending can't succeed.
                $this->spool?->remove($path);
                call_user_func($this->onIngestError, $message, microtime(true) - $start);
                $this->scheduleDrain(0);

                return null;
            }

            call_user_func($this->onIngestError, $message, microtime(true) - $start);
            $this->scheduleDrain($this->drainBackoff);
            $this->drainBackoff = min($this->drainBackoff * 2, self::DRAIN_MAX_BACKOFF);

            return null;
        });
    }

    /**
     * Whether a failed upload may succeed later: the network or Watchtower was unavailable,
     * busy or asked to retry, or the session needs renewing. A rejected payload is not.
     */
    /**
     * POST a payload to ingest. A failure before Watchtower answers becomes an
     * IngestUnreachableException, so callers can tell it from an HTTP error response.
     *
     * @return PromiseInterface<ResponseInterface>
     */
    private function send(IngestDetails $ingestDetails, string $payload): PromiseInterface
    {
        return $this->browser->post(
            url: $ingestDetails->ingestUrl,
            headers: [
                'authorization' => "Bearer {$ingestDetails->token}",
            ],
            body: $payload,
        )->catch(static function (Throwable $e): never {
            throw $e instanceof ResponseException ? $e : new IngestUnreachableException($e->getMessage(), previous: $e);
        });
    }

    private static function isRetryable(Throwable $e): bool
    {
        if (! $e instanceof ResponseException) {
            return true;
        }

        $status = $e->getResponse()->getStatusCode();

        return $status >= 500 || in_array($status, [401, 408, 425, 429], true);
    }

    private function stop(callable $errorHandler, float $duration, string $message, float|int|null $refreshIn = null): void
    {
        $this->pauseIngestion();

        $this->ingestDetails->markOverQuota($refreshIn);

        call_user_func($errorHandler, $message, $duration);
    }

    /**
     * @return array{0: string, 1: bool, 2: null|int|float}
     */
    private function parseException(Throwable $e): array
    {
        return $e instanceof ResponseException
            ? $this->parseResponse($e->getResponse())
            : [$e->getMessage(), false, null];
    }

    /**
     * @return array{0: string, 1: bool, 2: null|int|float}
     */
    private function parseResponse(ResponseInterface $response): array
    {
        $message = (string) $response->getBody();
        $stop = false;
        $refreshIn = null;

        try {
            /** @var array{ message?: string, refresh_in?: int|float, stop?: bool } $json */
            $json = json_decode($message, associative: true, flags: JSON_THROW_ON_ERROR);

            $message = $json['message'] ?? $message;
            $stop = $json['stop'] ?? $stop;
            $refreshIn = $json['refresh_in'] ?? $refreshIn;
        } catch (Throwable $exception) {
            //
        }

        if (strlen($message) > 1005) {
            $message = substr($message, 0, 1000).'[...]';
        }

        return [
            "{$response->getStatusCode()} [{$message}]",
            $stop,
            $refreshIn,
        ];
    }

    private function cancelDigestDelay(): void
    {
        if ($this->digestDelayTimer !== null) {
            $this->loop->cancelTimer($this->digestDelayTimer);
            $this->digestDelayTimer = null;
        }
    }
}

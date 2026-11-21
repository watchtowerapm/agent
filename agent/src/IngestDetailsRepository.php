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

use function array_fill;
use function call_user_func;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function microtime;
use function React\Promise\resolve;
use function strlen;
use function substr;

class IngestDetailsRepository
{
    private bool $overQuota = false;

    /**
     * @var PromiseInterface<IngestDetails|null>|null
     */
    private ?PromiseInterface $ingestDetails = null;

    private bool $hasAuthenticated = false;

    private int $consecutiveFailures = 0;

    private ?TimerInterface $refreshTimer = null;

    /**
     * Shortest gap between re-authentications caused by ingest being unreachable.
     */
    private const UNREACHABLE_REAUTHENTICATE_AFTER_SECONDS = 60;

    private ?int $unreachableInvalidatedAt = null;

    /**
     * @var list<int|float>|null
     */
    private ?array $quickRetryStrategyDurationsCache = null;

    /**
     * @param  (Closure(IngestDetails $ingestDetails, float $duration): mixed)  $onAuthenticationSuccess
     * @param  (Closure(string $message, float $duration): mixed)  $onAuthenticationError
     * @param  (Closure(): mixed)  $onUnderQuota
     */
    public function __construct(
        private LoopInterface $loop,
        private Browser $browser,
        private Clock $clock,
        private Closure $onAuthenticationSuccess,
        private Closure $onAuthenticationError,
        private Closure $onUnderQuota,
    ) {
        //
    }

    public function hydrate(): void
    {
        $this->get();
    }

    /**
     * @return PromiseInterface<IngestDetails|null>
     */
    public function get(): PromiseInterface
    {
        if ($this->ingestDetails === null) {
            return $this->ingestDetails = $this->refresh();
        }

        return $this->ingestDetails->then(function (?IngestDetails $ingestDetails): IngestDetails|PromiseInterface|null {
            if ($ingestDetails !== null && $this->clock->time() > $ingestDetails->expiresAt) {
                return $this->ingestDetails = $this->refresh();
            }

            return $ingestDetails;
        });
    }

    /**
     * Forget the cached ingest token, e.g. after ingest rejected it, so the next
     * get() authenticates again instead of reusing a token the server no longer accepts.
     */
    public function invalidate(): void
    {
        if ($this->overQuota) {
            return;
        }

        $this->ingestDetails = null;
    }

    /**
     * Forget the cached ingest details after ingest could not be reached, so a changed ingest
     * URL is picked up on the next attempt instead of at the next scheduled refresh. At most
     * once a minute: during an ingest outage this costs one authentication per minute.
     */
    public function invalidateUnreachable(): void
    {
        $now = $this->clock->time();

        if ($this->unreachableInvalidatedAt !== null && $now - $this->unreachableInvalidatedAt < self::UNREACHABLE_REAUTHENTICATE_AFTER_SECONDS) {
            return;
        }

        $this->unreachableInvalidatedAt = $now;

        $this->invalidate();
    }

    public function markOverQuota(int|float|null $refreshIn = null): void
    {
        $this->overQuota = true;

        $this->scheduleRefreshIn($refreshIn ?? 60 * 15);
    }

    /**
     * @return PromiseInterface<IngestDetails|null>
     */
    private function refresh(): PromiseInterface
    {
        $start = microtime(true);
        $duration = null;

        return $this->browser->post('/api/agent-auth', body: '{}')
            ->then(function (ResponseInterface $response) use ($start, &$duration): IngestDetails {
                $duration = microtime(true) - $start;

                $ingestDetails = $this->parseResponse($response);

                $this->scheduleRefreshIn($ingestDetails->refreshIn);

                call_user_func($this->onAuthenticationSuccess, $ingestDetails, $duration);

                if ($this->overQuota) {
                    $this->overQuota = false;

                    call_user_func($this->onUnderQuota);
                }

                $this->hasAuthenticated = true;
                $this->consecutiveFailures = 0;

                return $ingestDetails;
            })->catch(function (Throwable $e) use ($start, &$duration): null {
                $this->consecutiveFailures++;

                $duration ??= microtime(true) - $start;

                [$message, $stop, $refreshIn] = $this->parseException($e);

                if ($stop) {
                    $this->ingestDetails = resolve(null);
                }

                $this->scheduleRefreshIn($refreshIn);

                call_user_func($this->onAuthenticationError, $message, $duration);

                return null;
            });
    }

    private function scheduleRefreshIn(int|float $seconds): void
    {
        if ($this->refreshTimer !== null) {
            $this->loop->cancelTimer($this->refreshTimer);
        }

        $this->refreshTimer = $this->loop->addTimer($seconds, function (): void {
            $this->refreshTimer = null;

            $this->refresh()->then(function (?IngestDetails $ingestDetails): void {
                if ($ingestDetails) {
                    $this->ingestDetails = resolve($ingestDetails);
                }
            });
        });
    }

    private function parseResponse(ResponseInterface $response): IngestDetails
    {
        $body = $response->getBody()->getContents();

        $data = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);

        if (
            ! is_array($data) ||
            ! is_string($data['token'] ?? null) ||
            ! is_int($data['expires_in'] ?? null) ||
            ! is_int($data['refresh_in'] ?? null) ||
            ! is_string($data['ingest_url'] ?? null)
        ) {
            throw new RuntimeException("Invalid authentication response [{$body}]");
        }

        return new IngestDetails(
            token: $data['token'],
            expiresIn: $data['expires_in'],
            ingestUrl: $data['ingest_url'],
            refreshIn: $data['refresh_in'],
            expiresAt: $data['expires_in'] + $this->clock->time(),
            settings: is_array($data['settings'] ?? null) ? $data['settings'] : null,
        );
    }

    /**
     * @return array{0: string, 1: bool, 2: int|float}
     */
    private function parseException(Throwable $e): array
    {
        return $e instanceof ResponseException
            ? $this->parseResponseException($e)
            : $this->parseNonResponseException($e);
    }

    /**
     * @return array{0: string, 1: bool, 2: int|float}
     */
    private function parseResponseException(ResponseException $e): array
    {
        $message = (string) $e->getResponse()->getBody();
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

        $refreshIn ??= ($this->hasAuthenticated
            ? $this->slowRetryStrategy()
            : $this->quickRetryStrategy());

        return [
            "{$e->getResponse()->getStatusCode()} [{$message}]",
            $stop,
            $refreshIn,
        ];
    }

    /**
     * @return array{0: string, 1: bool, 2: int|float}
     */
    private function parseNonResponseException(Throwable $e): array
    {
        return $this->hasAuthenticated
            ? [$e->getMessage(), false, $this->slowRetryStrategy()]
            : [$e->getMessage(), false, $this->quickRetryStrategy()];
    }

    private function quickRetryStrategy(): int|float
    {
        $strategy = $this->quickRetryStrategyDurationsCache ??= [2.5, 5, 10, 15, 30, 60, 120, 240, ...array_fill(0, 12, 300)];

        return $strategy[$this->consecutiveFailures - 1] ?? 3_600;
    }

    private function slowRetryStrategy(): int
    {
        return $this->consecutiveFailures < 13
            ? 300
            : 3_600;
    }
}

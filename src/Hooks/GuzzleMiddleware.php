<?php

namespace Watchtower\Laravel\Hooks;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class GuzzleMiddleware
{
    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    /**
     * TODO record the failed responses as well.
     */
    public function __invoke(callable $handler): callable
    {
        if ($this->watchtower->config['filtering']['ignore_outgoing_requests'] || $this->watchtower->paused()) {
            return $handler;
        }

        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            try {
                $startMicrotime = $this->watchtower->clock->microtime();
            } catch (Throwable $e) {
                $this->watchtower->report($e, handled: true);

                return $handler($request, $options);
            }

            return $handler($request, $options)->then(function (ResponseInterface $response) use ($request, $startMicrotime): ResponseInterface {
                try {
                    $endMicrotime = $this->watchtower->clock->microtime();

                    $this->watchtower->outgoingRequest(
                        $startMicrotime, $endMicrotime,
                        $request, $response,
                    );
                } catch (Throwable $e) {
                    $this->watchtower->report($e, handled: true);
                }

                return $response;
            });
        };
    }
}

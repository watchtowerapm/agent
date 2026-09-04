<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Http\Client\Factory;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\Records\OutgoingRequest;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class HttpClientFactoryResolvedHandler
{
    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(Factory $factory): void
    {
        try {
            /**
             * @see OutgoingRequest
             */
            $factory->globalMiddleware($this->watchtower->guzzleMiddleware());
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

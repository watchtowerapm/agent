<?php

namespace Watchtower\Laravel\Hooks;

use Laravel\Octane\Events\RequestReceived;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class OctaneListener
{
    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function __construct(private Core $watchtower)
    {
        //
    }

    public function __invoke(RequestReceived $event): void // @phpstan-ignore class.notFound
    {
        try {
            $this->watchtower->prepareForRequest($event->request); // @phpstan-ignore class.notFound
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

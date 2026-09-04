<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Routing\Events\RouteMatched;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class RouteMatchedListener
{
    /**
     * @param  Core<RequestState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(RouteMatched $event): void
    {
        try {
            $this->watchtower->attachMiddlewareToRoute($event->route);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

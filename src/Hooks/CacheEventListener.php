<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Cache\Events\CacheEvent;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class CacheEventListener
{
    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(CacheEvent $event): void
    {
        try {
            $this->watchtower->cacheEvent($event);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

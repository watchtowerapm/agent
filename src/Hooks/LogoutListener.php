<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Auth\Events\Logout;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class LogoutListener
{
    /**
     * @param  Core<RequestState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(Logout $event): void
    {
        try {
            if ($event->user !== null) {
                $this->watchtower->remember($event->user);
            }
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

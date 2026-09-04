<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class NotificationListener
{
    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(NotificationSending|NotificationSent $event): void
    {
        try {
            $this->watchtower->notification($event);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

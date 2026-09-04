<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Console\Events\ScheduledTaskStarting;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;

/**
 * @internal
 */
final class ScheduledTaskStartingListener
{
    /**
     * @param  Core<CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(ScheduledTaskStarting $event): void
    {
        try {
            $this->watchtower->prepareForScheduledTask($event->task);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;

/**
 * @internal
 */
final class JobAttemptListener
{
    /**
     * @param  Core<CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(JobProcessed|JobReleasedAfterException|JobFailed $event): void
    {
        try {
            $this->watchtower->jobAttempt($event);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

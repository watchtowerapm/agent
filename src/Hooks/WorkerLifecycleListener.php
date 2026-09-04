<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;

/**
 * @internal
 */
final class WorkerLifecycleListener
{
    /**
     * @param  Core<CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(Looping|JobPopping|JobProcessing|WorkerStopping|CommandFinished $event): void
    {
        try {
            match ($event::class) {
                Looping::class, WorkerStopping::class => $this->watchtower->finishExecution()->waitForExecution(),
                CommandFinished::class => $event->command === 'queue:work' && $this->watchtower->finishExecution()->waitForExecution(),
                JobPopping::class => $this->watchtower->prepareForNextJob(),
                JobProcessing::class => $this->watchtower->prepareForJob($event->job),
            };
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Throwable;
use Watchtower\Laravel\Compatibility;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;

/**
 * @internal
 */
final class ScheduledTaskListener
{
    /**
     * @param  Core<CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(ScheduledTaskFinished|ScheduledTaskSkipped|ScheduledTaskFailed $event): void
    {
        // We report the exception here because the scheduler handles it after the task has finished and the data is ingested.
        // This ensures that the exception is captured in the scheduled task record.
        if ($event instanceof ScheduledTaskFailed) {
            $this->watchtower->report($event->exception, handled: false);
        }

        if ($this->isFinishedEventForFailedTask($event)) {
            return;
        }

        if ($event instanceof ScheduledTaskSkipped) {
            $this->watchtower->prepareForScheduledTask($event->task);
        }

        try {
            $this->watchtower->scheduledTask($event);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }

        $this->watchtower->finishExecution()->waitForExecution();
    }

    private function isFinishedEventForFailedTask(ScheduledTaskFinished|ScheduledTaskSkipped|ScheduledTaskFailed $event): bool
    {
        return Compatibility::$firesFinishedAndFailedEventsForScheduledConsoleCommands &&
            $event instanceof ScheduledTaskFinished &&
            $event->task->command !== null &&
            $event->task->exitCode !== 0 &&
            ! $event->task->runInBackground;
    }
}

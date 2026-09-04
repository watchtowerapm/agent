<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\ExecutionStage;
use Watchtower\Laravel\Facades\Watchtower;
use Watchtower\Laravel\Records\Command;
use Watchtower\Laravel\Records\JobAttempt;
use Watchtower\Laravel\State\CommandState;

/**
 * @internal
 */
final class CommandStartingListener
{
    private bool $hasRun = false;

    /**
     * @param  Core<CommandState>  $watchtower
     */
    public function __construct(
        private Dispatcher $events,
        private Core $watchtower,
        private ConsoleKernelContract $kernel,
    ) {
        //
    }

    public function __invoke(CommandStarting $event): void
    {
        if ($this->hasRun) {
            return;
        }

        $this->hasRun = true;

        try {
            match ($event->command) {
                'queue:work', 'queue:listen', 'horizon:work', 'vapor:work' => $this->registerJobHooks($event),
                'schedule:run', 'schedule:work', 'vapor:schedule' => $this->registerScheduledTaskHooks(),
                'help', 'inspire', 'schedule:finish' => null,
                default => $this->registerCommandHooks($event),
            };
        } catch (Throwable $e) {
            Watchtower::unrecoverableExceptionOccurred($e);
        }
    }

    private function registerJobHooks(CommandStarting $event): void
    {
        $this->watchtower->configureForJobs();

        /**
         * @see Core::finishExecution()
         * @see CommandState::flush()
         * @see CommandState::$timestamp
         * @see CommandState::$id
         */
        $this->events->listen([
            Looping::class,
            JobPopping::class,
            JobProcessing::class,
            WorkerStopping::class,
            CommandFinished::class,
        ], (new WorkerLifecycleListener($this->watchtower))(...));

        /**
         * @see JobAttempt
         * @see Core::finishExecution()
         */
        $this->events->listen([
            JobProcessed::class,
            JobReleasedAfterException::class,
            JobFailed::class,
        ], (new JobAttemptListener($this->watchtower))(...));

        if ($event->command === 'vapor:work') {
            $this->events->listen(CommandFinished::class, (new VaporWorkCommandFinishedListener($this->watchtower))(...));
        }
    }

    private function registerScheduledTaskHooks(): void
    {
        $this->watchtower->configureForScheduledTasks();

        $this->events->listen(ScheduledTaskStarting::class, (new ScheduledTaskStartingListener($this->watchtower))(...));

        /**
         * @see Core::finishExecution()
         */
        $this->events->listen([
            ScheduledTaskFinished::class,
            ScheduledTaskSkipped::class,
            ScheduledTaskFailed::class,
        ], (new ScheduledTaskListener($this->watchtower))(...));
    }

    private function registerCommandHooks(CommandStarting $event): void
    {
        if (! $this->kernel instanceof ConsoleKernel) {
            return;
        }

        $this->watchtower->configureCommandSampling($event->command);

        $this->watchtower->prepareForCommand($event->command);

        /**
         * @see ExecutionStage::Terminating
         */
        $this->events->listen(CommandFinished::class, (new CommandFinishedListener($this->watchtower))(...));

        /**
         * @see ExecutionStage::End
         * @see Command
         * @see Core::finishExecution()
         */
        $this->kernel->whenCommandLifecycleIsLongerThan(-1, new CommandLifecycleIsLongerThanHandler($this->watchtower));
    }
}

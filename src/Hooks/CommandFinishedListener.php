<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Console\Events\CommandFinished;
use Throwable;
use Watchtower\Laravel\Compatibility;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\ExecutionStage;
use Watchtower\Laravel\State\CommandState;

/**
 * @internal
 */
final class CommandFinishedListener
{
    /**
     * @param  Core<CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(CommandFinished $event): void
    {
        try {
            if ($this->watchtower->capturingCommandNamed($event->command) && ! Compatibility::$terminatingEventExists) {
                $this->watchtower->stage(ExecutionStage::Terminating);
            }
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

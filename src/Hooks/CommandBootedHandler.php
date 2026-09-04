<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Contracts\Foundation\Application;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\ExecutionStage;
use Watchtower\Laravel\State\CommandState;

/**
 * @internal
 */
final class CommandBootedHandler
{
    /**
     * @param  Core<CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(Application $app): void
    {
        try {
            $this->watchtower->stage(ExecutionStage::Action);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

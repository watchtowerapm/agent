<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Foundation\Events\Terminating;
use Throwable;
use Watchtower\Laravel\Compatibility;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\ExecutionStage;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class TerminatingListener
{
    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(Terminating $event): void
    {
        if (! Compatibility::$terminatingEventExists) {
            return;
        }

        try {
            $this->watchtower->stage(ExecutionStage::Terminating);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

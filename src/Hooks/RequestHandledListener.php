<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\ExecutionStage;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class RequestHandledListener
{
    /**
     * @param  Core<RequestState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(RequestHandled $event): void
    {
        try {
            $this->watchtower->stage(ExecutionStage::Sending);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

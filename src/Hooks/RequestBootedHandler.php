<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Contracts\Foundation\Application;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\ExecutionStage;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class RequestBootedHandler
{
    /**
     * @param  Core<RequestState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(Application $app): void
    {
        try {
            $this->watchtower->stage(ExecutionStage::BeforeMiddleware);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

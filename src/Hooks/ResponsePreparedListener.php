<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Routing\Events\ResponsePrepared;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\ExecutionStage;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class ResponsePreparedListener
{
    /**
     * @param  Core<RequestState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(ResponsePrepared $event): void
    {
        try {
            if ($this->watchtower->executionStageIs(ExecutionStage::Render)) {
                $this->watchtower->stage(ExecutionStage::AfterMiddleware);
            }
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

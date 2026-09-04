<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Routing\Events\PreparingResponse;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\ExecutionStage;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class PreparingResponseListener
{
    /**
     * @param  Core<RequestState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(PreparingResponse $event): void
    {
        try {
            if ($this->watchtower->executionStageIs(ExecutionStage::Action)) {
                $this->watchtower->stage(ExecutionStage::Render);
            }
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

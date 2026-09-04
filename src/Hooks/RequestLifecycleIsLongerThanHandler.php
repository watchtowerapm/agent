<?php

namespace Watchtower\Laravel\Hooks;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\ExecutionStage;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class RequestLifecycleIsLongerThanHandler
{
    /**
     * @param  Core<RequestState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(Carbon $startedAt, Request $request, Response $response): void
    {
        try {
            $this->watchtower->stage(ExecutionStage::End);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }

        try {
            $this->watchtower->captureUser();
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }

        try {
            $this->watchtower->request($request, $response);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }

        $this->watchtower->finishExecution();
    }
}

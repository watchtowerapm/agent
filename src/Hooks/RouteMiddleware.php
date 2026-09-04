<?php

namespace Watchtower\Laravel\Hooks;

use Closure;
use Illuminate\Http\Request;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\ExecutionStage;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class RouteMiddleware
{
    /**
     * @param  Core<RequestState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $this->watchtower->stage(ExecutionStage::Action);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }

        $response = $next($request);

        // If an exception occurs in the action phase, the usual
        // ResponsePrepared event is not fired. This fallback
        // ensures that we go to the AfterMiddleware stage.
        try {
            $this->watchtower->stage(ExecutionStage::AfterMiddleware);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }

        return $response;
    }
}

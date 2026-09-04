<?php

namespace Watchtower\Laravel\Hooks;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Watchtower\Laravel\Compatibility;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\ExecutionStage;
use Watchtower\Laravel\Facades\Watchtower;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class GlobalMiddleware
{
    private bool $hasHandledRequest = false;

    private bool $hasTerminated = false;

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
        if ($this->hasHandledRequest) {
            return $next($request);
        }

        $this->hasHandledRequest = true;

        try {
            $this->watchtower->configureRequestSampling();
        } catch (Throwable $e) {
            Watchtower::unrecoverableExceptionOccurred($e);
        }

        try {
            $this->watchtower->captureRequestPreview($request);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->hasTerminated || Compatibility::$terminatingEventExists) {
            return;
        }

        $this->hasTerminated = true;

        try {
            $this->watchtower->stage(ExecutionStage::Terminating);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

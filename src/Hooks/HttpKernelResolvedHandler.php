<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Kernel;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\ExecutionStage;
use Watchtower\Laravel\Facades\Watchtower;
use Watchtower\Laravel\Http\Middleware\Sample;
use Watchtower\Laravel\Records\Request;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class HttpKernelResolvedHandler
{
    /**
     * @param  Core<RequestState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(KernelContract $kernel, Application $app): void
    {
        if (! $kernel instanceof Kernel) {
            return;
        }

        try {
            /**
             * @see ExecutionStage::End
             * @see Request
             * @see Core::finishExecution()
             */
            $kernel->whenRequestLifecycleIsLongerThan(-1, new RequestLifecycleIsLongerThanHandler($this->watchtower));
        } catch (Throwable $e) {
            Watchtower::unrecoverableExceptionOccurred($e);
        }

        try {
            /**
             * @see ExecutionStage::Terminating
             */
            $kernel->prependMiddleware(GlobalMiddleware::class);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }

        try {
            $kernel->prependToMiddlewarePriority(Sample::class);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

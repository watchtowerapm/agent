<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\Records\Exception;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class ExceptionHandlerResolvedHandler
{
    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(ExceptionHandler $handler): void
    {
        try {
            if ($handler instanceof Handler) {
                /**
                 * @see Exception
                 */
                $handler->reportable(new ReportableHandler($this->watchtower));
            }
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Database\Events\QueryExecuted;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class QueryExecutedListener
{
    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(QueryExecuted $event): void
    {
        try {
            $this->watchtower->query($event);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

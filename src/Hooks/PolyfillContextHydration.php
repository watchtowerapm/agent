<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Queue\Events\JobProcessing;
use Throwable;
use Watchtower\Laravel\Compatibility;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class PolyfillContextHydration
{
    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(JobProcessing $event): void
    {
        try {
            $watchtower = $event->job->payload()['nightwatch'] ?? [];

            Compatibility::$context = [
                'nightwatch_trace_id' => $watchtower['nightwatch_trace_id'] ?? null,
                'nightwatch_should_sample' => $watchtower['nightwatch_should_sample'] ?? null,
                'nightwatch_user_id' => $watchtower['nightwatch_user_id'] ?? '',
            ];
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);

            Compatibility::$context = [];
        }
    }
}

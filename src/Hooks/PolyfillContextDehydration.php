<?php

namespace Watchtower\Laravel\Hooks;

use Throwable;
use Watchtower\Laravel\Compatibility;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class PolyfillContextDehydration
{
    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function __invoke(mixed $connection, mixed $queue, array $payload): array
    {
        $context = Compatibility::$context;

        try {
            if (($context['nightwatch_user_id'] ?? '') === '') {
                $context['nightwatch_user_id'] = $this->watchtower->executionState->user->resolvedUserId();
            }

            return [
                ...$payload,
                'nightwatch' => [
                    ...($payload['nightwatch'] ?? []), // @phpstan-ignore arrayUnpacking.nonIterable
                    'nightwatch_trace_id' => $context['nightwatch_trace_id'] ?? null,
                    'nightwatch_should_sample' => $context['nightwatch_should_sample'] ?? null,
                    'nightwatch_user_id' => $context['nightwatch_user_id'],
                ],
            ];
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);

            return $payload;
        }
    }
}

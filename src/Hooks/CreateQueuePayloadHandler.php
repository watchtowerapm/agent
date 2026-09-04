<?php

namespace Watchtower\Laravel\Hooks;

use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class CreateQueuePayloadHandler
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
        try {
            return [
                ...$payload,
                // Nightwatch-compatible job payload key used for trace correlation.
                'nightwatch' => [
                    ...($payload['nightwatch'] ?? []),  // @phpstan-ignore arrayUnpacking.nonIterable
                    'job_id' => $this->watchtower->uuid->make(),
                ],
            ];
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);

            return $payload;
        }
    }
}

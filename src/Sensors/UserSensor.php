<?php

namespace Watchtower\Laravel\Sensors;

use Watchtower\Laravel\Clock;
use Watchtower\Laravel\State\RequestState;
use Watchtower\Laravel\Types\Str;

/**
 * @internal
 */
final class UserSensor
{
    public function __construct(
        private RequestState $requestState,
        public Clock $clock,
    ) {
        //
    }

    /**
     * @return ?array<mixed>
     */
    public function __invoke(): ?array
    {
        $details = $this->requestState->user->details();

        if ($details === null) {
            return null;
        }

        return [
            'v' => 1,
            't' => 'user',
            'timestamp' => $this->clock->microtime(),
            'id' => Str::tinyText((string) $details['id']), // @phpstan-ignore cast.string
            'name' => Str::tinyText((string) ($details['name'] ?? '')), // @phpstan-ignore cast.string
            'username' => Str::tinyText((string) ($details['username'] ?? '')), // @phpstan-ignore cast.string
        ];
    }
}

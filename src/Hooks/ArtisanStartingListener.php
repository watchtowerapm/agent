<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Console\Events\ArtisanStarting;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;

/**
 * @internal
 */
final class ArtisanStartingListener
{
    /**
     * @param  Core<CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(ArtisanStarting $event): void
    {
        try {
            $this->watchtower->captureArtisan($event->artisan);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}

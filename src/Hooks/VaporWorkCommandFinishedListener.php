<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Console\Events\CommandFinished;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;

/**
 * @internal
 */
final class VaporWorkCommandFinishedListener
{
    /**
     * @param  Core<CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(CommandFinished $event): void
    {
        $this->watchtower->finishExecution();
    }
}

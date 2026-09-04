<?php

namespace Watchtower\Laravel\Hooks;

use Carbon\Carbon;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\ExecutionStage;
use Watchtower\Laravel\State\CommandState;

/**
 * @internal
 */
final class CommandLifecycleIsLongerThanHandler
{
    /**
     * @param  Core<CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(Carbon $startedAt, InputInterface $input, int $status): void
    {
        try {
            $this->watchtower->stage(ExecutionStage::End);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }

        try {
            $this->watchtower->command($input, $status);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }

        $this->watchtower->finishExecution();
    }
}

<?php

namespace Watchtower\Laravel\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
#[AsCommand(name: 'watchtower:status', description: 'Get the current status of the Watchtower agent.')]
final class StatusCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'watchtower:status';

    /**
     * @var string
     */
    protected $description = 'Get the current status of the Watchtower agent.';

    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function handle(Core $watchtower): int
    {
        if (! $watchtower->enabled()) {
            $this->components->error('Watchtower is disabled');

            return 1;
        }

        try {
            $watchtower->ingest->ping();

            $this->components->info('The Watchtower agent is running and accepting connections');

            return 0;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return 1;
        }
    }
}

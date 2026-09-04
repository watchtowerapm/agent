<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

use function str_repeat;

/**
 * @internal
 */
final class ReportableHandler
{
    public ?string $reservedMemory;

    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        $this->reservedMemory = str_repeat('n', 32768);
    }

    public function __invoke(Throwable $e): void
    {
        if (HandleExceptions::$reservedMemory === null) {
            $this->reservedMemory = null;
        }

        if ($this->watchtower->executionState->source === 'schedule') {
            return;
        }

        $this->watchtower->report($e);
    }
}

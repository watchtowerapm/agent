<?php

namespace Tests\Unit\Hooks;

use Illuminate\Foundation\Events\Terminating;
use RuntimeException;
use Tests\TestCase;
use Watchtower\Laravel\Compatibility;
use Watchtower\Laravel\ExecutionStage;
use Watchtower\Laravel\Hooks\TerminatingListener;

class TerminatingListenerTest extends TestCase
{
    public function test_it_gracefully_handles_exceptions(): void
    {
        $this->markTestSkippedWhen(! Compatibility::$terminatingEventExists, 'Requires a more recent framework version');

        $thrownInStageSensor = false;
        $this->core->sensor->stageSensor = function () use (&$thrownInStageSensor): void {
            $thrownInStageSensor = true;

            throw new RuntimeException('Whoops!');
        };
        $this->core->executionState->stage = ExecutionStage::Bootstrap;

        $event = new Terminating;

        $listener = new TerminatingListener($this->core);
        $listener($event);

        $this->assertTrue($thrownInStageSensor);
        $this->assertSame(1, $this->core->executionState->exceptions);
    }
}

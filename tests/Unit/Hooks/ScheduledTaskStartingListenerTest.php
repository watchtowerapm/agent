<?php

namespace Tests\Unit\Hooks;

use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use RuntimeException;
use Tests\TestCase;
use Watchtower\Laravel\Clock;
use Watchtower\Laravel\Hooks\ScheduledTaskStartingListener;

use function tap;

class ScheduledTaskStartingListenerTest extends TestCase
{
    protected function setUp(): void
    {
        $this->forceCommandExecutionState();

        parent::setUp();
    }

    public function test_it_gracefully_handles_exceptions(): void
    {
        $thrownInMicrotimeResolver = false;
        $this->core->clock = tap(new Clock, function ($clock) use (&$thrownInMicrotimeResolver): void {
            $clock->microtimeResolver = function () use (&$thrownInMicrotimeResolver): void {
                $thrownInMicrotimeResolver = true;

                throw new RuntimeException('Whoops!');
            };
        });

        $event = new ScheduledTaskStarting($this->app[Schedule::class]->command('php artisan inspire'));

        $handler = new ScheduledTaskStartingListener($this->core);
        $handler($event);

        $this->assertTrue($thrownInMicrotimeResolver);
        $this->assertSame(1, $this->core->executionState->exceptions);
    }
}

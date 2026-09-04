<?php

namespace Tests\Feature\Facades;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\Facades\Watchtower;

class WatchtowerFacadeTest extends TestCase
{
    public function test_it_resolves_to_bound_singleton_instance_of_the_core_class(): void
    {
        $this->assertInstanceOf(Core::class, Watchtower::getFacadeRoot());

        $this->assertSame($this->app[Core::class], Watchtower::getFacadeRoot());

        Facade::clearResolvedInstances();
        $this->assertSame($this->app[Core::class], Watchtower::getFacadeRoot());
    }

    public function test_it_silently_discards_unrecoverable_exceptions_by_default(): void
    {
        (new ReflectionClass(Watchtower::class))->getProperty('handleUnrecoverableExceptionsUsing')->setValue(null);
        $calls = 0;
        Log::listen(function () use (&$calls): void {
            $calls++;
        });

        Watchtower::unrecoverableExceptionOccurred(new RuntimeException('Whoops!'));

        $this->assertSame(0, $calls);
    }

    public function test_it_can_register_a_callback_to_handle_unrecoverable_exceptions(): void
    {
        $handled = [];
        Watchtower::handleUnrecoverableExceptionsUsing(function (Throwable $e) use (&$handled): void {
            $handled[] = $e;
        });

        Watchtower::unrecoverableExceptionOccurred($first = new RuntimeException('Whoops!'));
        Watchtower::unrecoverableExceptionOccurred($second = new RuntimeException('Whoops!'));

        $this->assertSame([
            $first,
            $second,
        ], $handled);
    }

    public function test_it_handles_unrecoverable_exceptions_statelessly(): void
    {
        $this->app->forgetInstance(Core::class);
        $resolved = false;
        Watchtower::resolved(function () use (&$resolved): void {
            $resolved = true;
        });

        $handled = [];
        Watchtower::handleUnrecoverableExceptionsUsing(function (Throwable $e) use (&$handled): void {
            $handled[] = $e;
        });
        Watchtower::unrecoverableExceptionOccurred($first = new RuntimeException('Whoops!'));

        $this->assertFalse($resolved);
        $this->assertCount(1, $handled);
        $this->assertFalse($this->app->resolved(Core::class));
    }

    public function test_it_silences_exceptions_thrown_while_handling_exceptions(): void
    {
        Watchtower::handleUnrecoverableExceptionsUsing(function (): object {
            // Should return an object. Returning an int to cause an exception.
            return 5;
        });

        Watchtower::unrecoverableExceptionOccurred(new RuntimeException('Whoops!'));

        $this->assertTrue(true);
    }
}

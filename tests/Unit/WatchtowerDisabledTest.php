<?php

namespace Tests\Unit;

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;
use Watchtower\Laravel\Facades\Watchtower;

class WatchtowerDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        Env::getRepository()->set('WATCHTOWER_ENABLED', '0');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Env::getRepository()->clear('WATCHTOWER_ENABLED');
    }

    public function test_it_can_disable_watchtower_via_the_environment(): void
    {
        $this->assertFalse($this->core->enabled());
    }

    public function test_it_gracefully_ignores_reported_exceptions_when_watchtower_is_disabled(): void
    {
        $ingest = $this->fakeIngest();
        Route::get('/users', fn () => Watchtower::report(new RuntimeException));

        $response = $this->get('/users');

        $response->assertOk();
        $ingest->assertWrittenTimes(0);
        $this->assertSame(0, $this->core->executionState->exceptions);
    }

    public function test_it_gracefully_ignores_logs_when_watchtower_is_disabled(): void
    {
        $ingest = $this->fakeIngest();
        Route::get('/users', fn () => Log::channel('watchtower')->info('Hello world'));

        $response = $this->get('/users');

        $response->assertOk();
        $ingest->assertWrittenTimes(0);
        $this->assertSame(0, $this->core->executionState->logs);
        $this->assertSame(0, $this->core->executionState->exceptions);
    }
}

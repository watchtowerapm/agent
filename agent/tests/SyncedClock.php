<?php

namespace Tests;

use Watchtower\LaravelAgent\Contracts\Clock;

class SyncedClock implements Clock
{
    public function __construct(public float $now)
    {
        //
    }

    public function time(): int
    {
        return (int) $this->now;
    }
}

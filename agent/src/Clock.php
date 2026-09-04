<?php

namespace Watchtower\LaravelAgent;

use Watchtower\LaravelAgent\Contracts\Clock as ClockContract;

use function time;

class Clock implements ClockContract
{
    public function time(): int
    {
        return time();
    }
}

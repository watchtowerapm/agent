<?php

namespace Watchtower\Laravel\Records;

final class Notification
{
    public function __construct(
        public readonly string $channel,
        public readonly string $class,
        public readonly int $duration,
    ) {
        //
    }
}

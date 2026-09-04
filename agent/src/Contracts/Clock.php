<?php

namespace Watchtower\LaravelAgent\Contracts;

interface Clock
{
    public function time(): int;
}

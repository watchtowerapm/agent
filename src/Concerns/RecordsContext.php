<?php

namespace Watchtower\Laravel\Concerns;

use Illuminate\Support\Facades\Context;
use Throwable;
use Watchtower\Laravel\Compatibility;
use Watchtower\Laravel\Facades\Watchtower;
use Watchtower\Laravel\Types\Str;

use function json_encode;

/**
 * @internal
 */
trait RecordsContext
{
    private function serializedContext(): string
    {
        if (! Compatibility::$contextExists) {
            return '';
        }

        try {
            return Str::text(json_encode((object) Context::all(), flags: JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            Watchtower::unrecoverableExceptionOccurred($e);

            return '{"_nightwatch_error":"Failed to serialize context"}';
        }
    }
}

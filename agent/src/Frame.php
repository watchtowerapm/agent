<?php

namespace Watchtower\LaravelAgent;

use RuntimeException;

use function json_decode;
use function json_encode;
use function strlen;
use function strpos;
use function substr;

/**
 * Length-prefixed JSON frames: `{byte_length}\\n{json}`.
 *
 * @internal
 */
final class Frame
{
    /**
     * @param  array<string, mixed>  $message
     */
    public static function encode(array $message): string
    {
        $json = json_encode($message, flags: JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return strlen($json)."\n".$json;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function decodeAll(string $wire): array
    {
        $messages = [];
        $offset = 0;
        $length = strlen($wire);

        while ($offset < $length) {
            [$message, $offset] = self::decodeOne($wire, $offset);
            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    public static function decodeOne(string $wire, int $offset = 0): array
    {
        $newline = strpos($wire, "\n", $offset);

        if ($newline === false) {
            throw new RuntimeException('Invalid Watchtower frame: missing length prefix.');
        }

        $size = (int) substr($wire, $offset, $newline - $offset);
        $start = $newline + 1;
        $end = $start + $size;

        if ($size < 1 || strlen($wire) < $end) {
            throw new RuntimeException('Invalid Watchtower frame: truncated body.');
        }

        /** @var array<string, mixed> $message */
        $message = json_decode(substr($wire, $start, $size), true, 512, JSON_THROW_ON_ERROR);

        return [$message, $end];
    }
}

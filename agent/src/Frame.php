<?php

namespace Watchtower\LaravelAgent;

use JsonException;
use UnexpectedValueException;

use function is_array;
use function json_decode;
use function json_encode;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function strspn;
use function substr;

/**
 * Length-prefixed JSON frames: `{byte_length}\\n{json}`.
 *
 * Keep identical to the other copy (SDK: `src/Transport/Frame.php`, sidecar: `agent/src/Frame.php`).
 *
 * @internal
 */
final class Frame
{
    /**
     * Longest accepted length prefix (digits). 9 digits covers MAX_FRAME_BYTES.
     */
    private const MAX_PREFIX_DIGITS = 9;

    /**
     * @param  array<string, mixed>  $message
     */
    public static function encode(array $message): string
    {
        $json = self::json($message);

        return strlen($json)."\n".$json;
    }

    /**
     * A frame whose member $key is spliced in, byte for byte, as the body's last member. Used for
     * batch records: they are encoded once, signed as these bytes, and the sidecar forwards the
     * same bytes without decoding and encoding them again.
     *
     * @param  array<string, mixed>  $message
     */
    public static function encodeWithRawMember(array $message, string $key, string $json): string
    {
        $body = substr(self::json($message), 0, -1).($message === [] ? '' : ',').'"'.$key.'":'.$json.'}';

        return strlen($body)."\n".$body;
    }

    /**
     * The raw JSON of member $key in a frame body written by encodeWithRawMember(), or null when
     * the body isn't shaped that way.
     */
    public static function rawLastMember(string $body, string $key): ?string
    {
        $marker = '"'.$key.'":';
        $at = strpos($body, ",{$marker}");
        $at = $at === false ? (str_starts_with($body, "{{$marker}") ? 0 : false) : $at;

        if ($at === false || ! str_ends_with($body, '}')) {
            return null;
        }

        return substr($body, $at + 1 + strlen($marker), -1);
    }

    /**
     * JSON with the flags every frame uses.
     *
     * @param  array<mixed>  $value
     */
    public static function json(array $value): string
    {
        return json_encode($value, flags: JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
     * Decode one complete frame. Throws when the frame is incomplete or invalid.
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    public static function decodeOne(string $wire, int $offset = 0): array
    {
        return self::tryDecodeOne($wire, $offset)
            ?? throw new UnexpectedValueException('Invalid Watchtower frame: truncated body.');
    }

    /**
     * Decode one frame if enough bytes have arrived.
     *
     * Returns null when more bytes are needed. Throws UnexpectedValueException when the bytes
     * can never become a valid frame (bad length prefix, oversized frame, or a body that is not
     * a JSON object), so callers can fail fast instead of waiting for a timeout.
     *
     * @return array{0: array<string, mixed>, 1: int}|null
     */
    public static function tryDecodeOne(string $wire, int $offset = 0): ?array
    {
        $newline = strpos($wire, "\n", $offset);

        if ($newline === false) {
            if (strlen($wire) - $offset > self::MAX_PREFIX_DIGITS) {
                throw new UnexpectedValueException('Invalid Watchtower frame: missing length prefix.');
            }

            return null;
        }

        $prefix = substr($wire, $offset, $newline - $offset);

        if ($prefix === '' || strlen($prefix) > self::MAX_PREFIX_DIGITS || strspn($prefix, '0123456789') !== strlen($prefix)) {
            throw new UnexpectedValueException('Invalid Watchtower frame: length prefix must be a positive integer.');
        }

        $size = (int) $prefix;

        if ($size < 2 || $size > Protocol::MAX_FRAME_BYTES) {
            throw new UnexpectedValueException("Invalid Watchtower frame: size [{$size}] is out of range.");
        }

        $start = $newline + 1;
        $end = $start + $size;

        if (strlen($wire) < $end) {
            return null;
        }

        try {
            $message = json_decode(substr($wire, $start, $size), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException('Invalid Watchtower frame: body is not valid JSON.', previous: $e);
        }

        if (! is_array($message)) {
            throw new UnexpectedValueException('Invalid Watchtower frame: body must be a JSON object.');
        }

        /** @var array<string, mixed> $message */
        return [$message, $end];
    }
}

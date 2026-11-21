<?php

namespace Watchtower\LaravelAgent;

use function bin2hex;
use function hash;
use function hash_equals;
use function hash_hkdf;
use function hash_hmac;
use function implode;
use function is_string;
use function random_bytes;
use function strlen;

/**
 * HMAC-SHA256 signatures for the agent ↔ sidecar session. Both sides derive the same key from
 * the full `WATCHTOWER_TOKEN`; the token itself never goes over the socket.
 *
 * HELLO carries the client's nonce, WELCOME the sidecar's nonce and a session id, and every
 * command is signed together with both nonces, the session id, its sequence and a digest of its
 * body. A recorded exchange can't be replayed: each connection gets fresh nonces and a session.
 *
 * The body is covered by a fast xxh128 digest rather than by the HMAC itself: hashing a full
 * batch with SHA-256 costs more than encoding it. That authenticates the sender and binds the
 * body to the session, but xxh128 isn't collision-resistant, so it doesn't protect a batch from
 * someone who can already rewrite the connection's traffic (root on the host, or raw access to
 * a shared container network).
 *
 * Keep identical to the other copy (SDK: `src/Transport/Mac.php`, sidecar: `agent/src/Mac.php`).
 *
 * @internal
 */
final class Mac
{
    public const NONCE_BYTES = 16;

    public static function key(string $token): string
    {
        return hash_hkdf('sha256', "watchtower:{$token}", 32, 'watchtower-sidecar-session');
    }

    public static function nonce(): string
    {
        return bin2hex(random_bytes(self::NONCE_BYTES));
    }

    public static function hello(string $key, int $protocolVersion, string $clientNonce): string
    {
        return self::sign($key, ['hello', (string) $protocolVersion, $clientNonce]);
    }

    public static function welcome(string $key, string $clientNonce, string $sessionId, string $serverNonce): string
    {
        return self::sign($key, ['welcome', $clientNonce, $sessionId, $serverNonce]);
    }

    public static function command(string $key, string $type, string $sessionId, int $sequence, string $clientNonce, string $serverNonce, string $body): string
    {
        return self::sign($key, [$type, $sessionId, (string) $sequence, $clientNonce, $serverNonce, (string) strlen($body), hash('xxh128', $body)]);
    }

    public static function matches(string $expected, mixed $given): bool
    {
        return is_string($given) && hash_equals($expected, $given);
    }

    /**
     * @param  list<string>  $parts
     */
    private static function sign(string $key, array $parts): string
    {
        return hash_hmac('sha256', implode("\n", $parts), $key);
    }
}

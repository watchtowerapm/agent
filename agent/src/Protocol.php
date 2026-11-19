<?php

namespace Watchtower\LaravelAgent;

/**
 * Watchtower agent ↔ sidecar wire protocol.
 *
 * @internal
 */
final class Protocol
{
    public const NAME = 'watchtower';

    public const VERSION = 1;

    public const BATCH_VERSION = 1;

    public const MAX_BATCH = 500;

    /**
     * Largest frame body either side accepts (16 MiB).
     */
    public const MAX_FRAME_BYTES = 16 * 1024 * 1024;

    /**
     * Seconds the sidecar keeps a connection that has not finished its exchange.
     */
    public const IDLE_TIMEOUT_SECONDS = 10;

    public const TYPE_HELLO = 'hello';

    public const TYPE_WELCOME = 'welcome';

    public const TYPE_TELEMETRY_BATCH = 'telemetry_batch';

    public const TYPE_PING = 'ping';

    public const TYPE_ACK = 'ack';

    public const TYPE_ERROR = 'error';

    public const ERROR_INVALID_FRAME = 'invalid_frame';

    public const ERROR_EXPECTED_HELLO = 'expected_hello';

    public const ERROR_TOKEN_MISMATCH = 'token_mismatch';

    public const ERROR_UNSUPPORTED_PROTOCOL = 'unsupported_protocol';

    public const ERROR_UNEXPECTED_TYPE = 'unexpected_type';

    public const ERROR_SESSION_MISMATCH = 'session_mismatch';

    public const ERROR_SEQUENCE_MISMATCH = 'sequence_mismatch';

    public const ERROR_UNSUPPORTED_BATCH_VERSION = 'unsupported_batch_version';

    public const ERROR_INVALID_RECORDS = 'invalid_records';

    public const ERROR_BATCH_TOO_LARGE = 'batch_too_large';

    public const ERROR_IDLE_TIMEOUT = 'idle_timeout';
}

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

    public const TYPE_HELLO = 'hello';

    public const TYPE_WELCOME = 'welcome';

    public const TYPE_TELEMETRY_BATCH = 'telemetry_batch';

    public const TYPE_PING = 'ping';

    public const TYPE_ACK = 'ack';

    public const TYPE_ERROR = 'error';
}

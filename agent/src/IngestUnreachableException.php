<?php

namespace Watchtower\LaravelAgent;

use RuntimeException;

/**
 * The ingest request failed before Watchtower answered (DNS, connection, TLS or timeout).
 *
 * @internal
 */
final class IngestUnreachableException extends RuntimeException
{
    //
}

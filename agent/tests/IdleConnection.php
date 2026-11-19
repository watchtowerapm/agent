<?php

namespace Tests;

/**
 * Opens a connection that never sends anything, to exercise the sidecar's idle timeout.
 */
class IdleConnection
{
    public function __construct(
        public TcpServerFake $server,
    ) {
        //
    }

    public function __invoke(): void
    {
        $connection = new Connection;

        $this->server->emit('connection', [$connection]);
        $this->server->connections[] = $connection;
    }
}

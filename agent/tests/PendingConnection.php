<?php

namespace Tests;

use Throwable;
use Watchtower\LaravelAgent\Frame;

use function array_shift;
use function is_string;

class PendingConnection
{
    public function __construct(
        public TcpServerFake $server,
        public string $payload,
    ) {
        //
    }

    public function __invoke(): void
    {
        $connection = new Connection;

        $this->server->emit('connection', [$connection]);

        try {
            $messages = Frame::decodeAll($this->payload);
        } catch (Throwable) {
            $connection->emit('data', [$this->payload]);
            $connection->emit('close');
            $this->server->connections[] = $connection;

            return;
        }

        $first = array_shift($messages);

        if ($first === null) {
            $connection->emit('close');
            $this->server->connections[] = $connection;

            return;
        }

        /** @var array<string, mixed> $first */
        $connection->emit('data', [Frame::encode($first)]);

        if ($messages !== []) {
            $sessionId = $this->issuedSessionId($connection);
            $wire = '';

            foreach ($messages as $message) {
                if ($sessionId !== null && ($message['session_id'] ?? null) === 'sess_test') {
                    $message['session_id'] = $sessionId;
                }

                $wire .= Frame::encode($message);
            }

            $connection->emit('data', [$wire]);
        }

        $connection->emit('close');
        $this->server->connections[] = $connection;
    }

    private function issuedSessionId(Connection $connection): ?string
    {
        try {
            $messages = Frame::decodeAll($connection->payload);
        } catch (Throwable) {
            return null;
        }

        $sessionId = $messages[0]['session_id'] ?? null;

        return is_string($sessionId) ? $sessionId : null;
    }
}

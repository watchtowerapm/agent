<?php

namespace Tests;

use Throwable;
use Watchtower\LaravelAgent\Frame;
use Watchtower\LaravelAgent\Mac;

use function array_shift;
use function is_array;
use function is_int;
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
            $welcome = $this->welcome($connection);
            $sessionId = $welcome['session_id'] ?? null;
            $sessionId = is_string($sessionId) ? $sessionId : null;
            $wire = '';

            foreach ($messages as $message) {
                if ($sessionId !== null && ($message['session_id'] ?? null) === 'sess_test') {
                    $message['session_id'] = $sessionId;
                }

                // Sign the command the way the application would, now that WELCOME is known.
                if (($message['mac'] ?? null) === TcpServerFake::SIGN && is_string($welcome['nonce'] ?? null)) {
                    $type = is_string($message['type'] ?? null) ? $message['type'] : '';
                    $records = $type === 'telemetry_batch' && is_array($message['records'] ?? null) ? Frame::json($message['records']) : '';

                    $message['mac'] = Mac::command(
                        Mac::key(TestCase::token()),
                        $type,
                        is_string($message['session_id'] ?? null) ? $message['session_id'] : '',
                        is_int($message['sequence'] ?? null) ? $message['sequence'] : 0,
                        is_string($first['nonce'] ?? null) ? $first['nonce'] : '',
                        $welcome['nonce'],
                        $records,
                    );
                }

                $wire .= Frame::encode($message);
            }

            $connection->emit('data', [$wire]);
        }

        $connection->emit('close');
        $this->server->connections[] = $connection;
    }

    /**
     * @return array<string, mixed>
     */
    private function welcome(Connection $connection): array
    {
        try {
            return Frame::decodeAll($connection->payload)[0] ?? [];
        } catch (Throwable) {
            return [];
        }
    }
}

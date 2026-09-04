<?php

namespace Tests;

use Evenement\EventEmitter;
use PHPUnit\Framework\Assert;
use React\Socket\ServerInterface;
use RuntimeException;

use Watchtower\LaravelAgent\Frame;
use Watchtower\LaravelAgent\Protocol;

use function is_string;

class TcpServerFake extends EventEmitter implements ServerInterface
{
    /**
     * @var list<Connection>
     */
    public array $connections = [];

    public bool $closed = false;

    /**
     * @param  string|list<array<string, mixed>>  $records
     */
    public function pendingConnection(array|string $records): PendingConnection
    {
        $tokenHash = TestCase::tokenHash();

        if (is_string($records)) {
            if ($records === 'PING') {
                $records = self::helloFrame($tokenHash).self::pingFrame();
            }

            return new PendingConnection($this, $records);
        }

        return new PendingConnection($this, self::helloFrame($tokenHash).self::batchFrame($records));
    }

    public static function helloFrame(string $tokenHash, int $protocolVersion = Protocol::VERSION, string $protocol = Protocol::NAME): string
    {
        return Frame::encode([
            'type' => Protocol::TYPE_HELLO,
            'protocol' => $protocol,
            'protocol_version' => $protocolVersion,
            'agent_version' => '2.0.0',
            'sdk' => 'laravel',
            'php_version' => PHP_VERSION,
            'token_hash' => $tokenHash,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    public static function batchFrame(array $records): string
    {
        return Frame::encode([
            'type' => Protocol::TYPE_TELEMETRY_BATCH,
            'protocol' => Protocol::NAME,
            'protocol_version' => Protocol::VERSION,
            'batch_version' => Protocol::BATCH_VERSION,
            'session_id' => 'sess_test',
            'sequence' => 1,
            'records' => $records,
        ]);
    }

    public static function pingFrame(): string
    {
        return Frame::encode([
            'type' => Protocol::TYPE_PING,
            'protocol_version' => Protocol::VERSION,
            'session_id' => 'sess_test',
            'sequence' => 1,
        ]);
    }

    public function getAddress()
    {
        throw new RuntimeException(__FUNCTION__);
    }

    public function pause()
    {
        throw new RuntimeException(__FUNCTION__);
    }

    public function resume()
    {
        throw new RuntimeException(__FUNCTION__);
    }

    public function close()
    {
        $this->closed = true;
    }

    /**
     * @param  list<Connection>  $connections
     */
    public function assertHandled(array $connections): self
    {
        Assert::assertCount(count($connections), $this->connections);

        foreach ($this->connections as $i => $actual) {
            $actual->assertMatches($connections[$i]);
        }

        return $this;
    }

    public function assertOpen(): self
    {
        Assert::assertFalse($this->closed);

        return $this;
    }

    public function assertClosed(): self
    {
        Assert::assertTrue($this->closed);

        return $this;
    }
}

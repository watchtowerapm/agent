<?php

namespace Tests;

use Evenement\EventEmitter;
use PHPUnit\Framework\Assert;
use React\Socket\ServerInterface;
use RuntimeException;
use Watchtower\LaravelAgent\Frame;
use Watchtower\LaravelAgent\Mac;
use Watchtower\LaravelAgent\Protocol;

use function count;
use function is_string;

class TcpServerFake extends EventEmitter implements ServerInterface
{
    /** The nonce the fake application sends in HELLO. */
    public const CLIENT_NONCE = '00112233445566778899aabbccddeeff';

    /** Placeholder MAC on a command; PendingConnection signs it once the sidecar's WELCOME is known. */
    public const SIGN = 'sign';

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

    /**
     * Signed with the real token when $tokenHash is the real hash, otherwise with a wrong one.
     * Unsigned, it is a protocol 1 HELLO (token hash only).
     */
    public static function helloFrame(string $tokenHash, int $protocolVersion = Protocol::VERSION, string $protocol = Protocol::NAME, bool $signed = true): string
    {
        $key = Mac::key($tokenHash === TestCase::tokenHash() ? TestCase::token() : 'not-the-token');

        return Frame::encode([
            'type' => Protocol::TYPE_HELLO,
            'protocol' => $protocol,
            'protocol_version' => $protocolVersion,
            'agent_version' => '1.0.0',
            'sdk' => 'laravel',
            'php_version' => PHP_VERSION,
            'token_hash' => $tokenHash,
            ...($signed ? ['nonce' => self::CLIENT_NONCE, 'mac' => Mac::hello($key, $protocolVersion, self::CLIENT_NONCE)] : []),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    public static function batchFrame(
        array $records,
        string $sessionId = 'sess_test',
        int $batchVersion = Protocol::BATCH_VERSION,
        int $sequence = 1,
        string $mac = self::SIGN,
    ): string {
        return Frame::encode([
            'type' => Protocol::TYPE_TELEMETRY_BATCH,
            'protocol' => Protocol::NAME,
            'protocol_version' => Protocol::VERSION,
            'batch_version' => $batchVersion,
            'session_id' => $sessionId,
            'sequence' => $sequence,
            'mac' => $mac,
            'records' => $records,
        ]);
    }

    public static function pingFrame(string $sessionId = 'sess_test', int $sequence = 1): string
    {
        return Frame::encode([
            'type' => Protocol::TYPE_PING,
            'protocol_version' => Protocol::VERSION,
            'session_id' => $sessionId,
            'sequence' => $sequence,
            'mac' => self::SIGN,
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

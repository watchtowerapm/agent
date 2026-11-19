<?php

namespace Tests\Feature;

use Tests\BrowserFake;
use Tests\Connection;
use Tests\IdleConnection;
use Tests\LoopFake;
use Tests\Request;
use Tests\Response;
use Tests\TcpServerFake;
use Tests\TestCase;
use Tests\Timer;
use Watchtower\LaravelAgent\Frame;
use Watchtower\LaravelAgent\Protocol;

use function array_fill;
use function count;

class ServerTest extends TestCase
{
    public function test_it_responds_with_ok(): void
    {
        $loop = new LoopFake(runForSeconds: 2);
        $server = new TcpServerFake;
        $ingestDetailsBrowser = new BrowserFake([Response::jwt()]);
        $ingestBrowser = new BrowserFake;

        $loop->addTimer(1, $server->pendingConnection([['t' => 'request']]));

        [$output, $e] = $this->runAgent(
            via: 'source',
            ingestDetailsBrowser: $ingestDetailsBrowser,
            ingestBrowser: $ingestBrowser,
            loop: $loop,
            server: $server,
        );

        $this->assertNull($e, $e?->getMessage() ?? '');
        $server->assertHandled([
            Connection::ok(),
        ]);
        $server->assertOpen();
        $this->assertLogMatches(<<<'OUTPUT'
            {date} {info} Authentication successful {duration}
            OUTPUT, $output);
        $loop->assertRun([
            new Timer(interval: 1, runAt: 1, scheduledAt: 0, scheduledBy: $this->functionName()),
        ]);
        $loop->assertPending([
            new Timer(interval: 10, runAt: 11, scheduledAt: 1, scheduledBy: 'Watchtower\LaravelAgent\Ingest::write'),
            new Timer(interval: 3_600, runAt: 3_600, scheduledAt: 0, scheduledBy: 'Watchtower\LaravelAgent\IngestDetailsRepository::scheduleRefreshIn'),
        ]);
        $ingestDetailsBrowser->assertSent([
            Request::json('/api/agent-auth'),
        ]);
        $ingestDetailsBrowser->assertPending([]);
        $ingestBrowser->assertSent([]);
        $ingestBrowser->assertPending([]);
    }

    public function test_it_can_be_pinged(): void
    {
        $loop = new LoopFake(runForSeconds: 1);
        $server = new TcpServerFake;
        $ingestDetailsBrowser = new BrowserFake([Response::jwt()]);
        $ingestBrowser = new BrowserFake;

        $loop->addTimer(0, $server->pendingConnection('PING'));

        [$output, $e] = $this->runAgent(
            via: 'source',
            ingestDetailsBrowser: $ingestDetailsBrowser,
            ingestBrowser: $ingestBrowser,
            loop: $loop,
            server: $server,
        );

        $this->assertNull($e, $e?->getMessage() ?? '');
        $server->assertHandled([
            Connection::ok(),
        ]);
        $server->assertOpen();
        $this->assertLogMatches(<<<'OUTPUT'
            {date} {info} Authentication successful {duration}
            OUTPUT, $output);
        $loop->assertRun([
            new Timer(interval: 0, runAt: 0, scheduledAt: 0, scheduledBy: $this->functionName()),
        ]);
        $loop->assertPending([
            new Timer(interval: 3_600, runAt: 3_600, scheduledAt: 0, scheduledBy: 'Watchtower\LaravelAgent\IngestDetailsRepository::scheduleRefreshIn'),
        ]);
        $ingestDetailsBrowser->assertSent([
            Request::json('/api/agent-auth'),
        ]);
        $ingestDetailsBrowser->assertPending([]);
        $ingestBrowser->assertSent([]);
        $ingestBrowser->assertPending([]);
    }

    public function test_it_stops_loop_when_an_incorrect_payload_version_is_received(): void
    {
        $tokenHash = self::tokenHash();

        $loop = new LoopFake(runForSeconds: 2);
        $server = new TcpServerFake;
        $ingestDetailsBrowser = new BrowserFake([Response::jwt()]);
        $ingestBrowser = new BrowserFake([]);

        $loop->addTimer(1, $server->pendingConnection(TcpServerFake::helloFrame($tokenHash, protocolVersion: 99)));

        [$output, $e] = $this->runAgent(
            via: 'source',
            ingestDetailsBrowser: $ingestDetailsBrowser,
            ingestBrowser: $ingestBrowser,
            loop: $loop,
            server: $server,
        );

        $this->assertNull($e, $e?->getMessage() ?? '');
        $server->assertHandled([
            Connection::rejected(),
        ]);
        $server->assertClosed();
        $this->assertLogMatches(<<<'OUTPUT'
        {date} {info} Authentication successful {duration}
        {date} {info} Incoming payload version has changed
        {date} {info} Shutting down
        OUTPUT, $output);
        $loop->assertRun([
            new Timer(interval: 1, runAt: 1, scheduledAt: 0, scheduledBy: $this->functionName()),
        ]);
        $loop->assertPending([
            new Timer(interval: 3_600, runAt: null, scheduledAt: 0, scheduledBy: 'Watchtower\LaravelAgent\IngestDetailsRepository::scheduleRefreshIn'),
        ]);
        $this->assertFalse($loop->running);
        $ingestDetailsBrowser->assertSent([
            Request::json('/api/agent-auth'),
        ]);
        $ingestDetailsBrowser->assertPending([]);
        $ingestBrowser->assertSent([]);
        $ingestBrowser->assertPending([]);
    }

    public function test_it_errors_when_an_incorrect_token_hash_is_received(): void
    {
        $tokenHash = self::tokenHash();
        $loop = new LoopFake(runForSeconds: 40);
        $server = new TcpServerFake;
        $ingestDetailsBrowser = new BrowserFake([Response::jwt()]);
        $ingestBrowser = new BrowserFake([
            Response::ingested(),
        ]);

        $loop->addTimer(1, $server->pendingConnection(TcpServerFake::helloFrame('INVALID')));
        $loop->addTimer(20, $server->pendingConnection([['t' => 'request']]));

        [$output, $e] = $this->runAgent(
            via: 'source',
            ingestDetailsBrowser: $ingestDetailsBrowser,
            ingestBrowser: $ingestBrowser,
            loop: $loop,
            server: $server,
        );

        $this->assertNull($e, $e?->getMessage() ?? '');
        $server->assertOpen();
        $server->assertHandled([
            Connection::rejected(),
            Connection::ok(),
        ]);
        $this->assertLogMatches(<<<'OUTPUT'
        {date} {info} Authentication successful {duration}
        {date} {error} Incoming token hash mismatch! Check your application/agent configuration.
        {date} {info} Ingest successful {duration}
        OUTPUT, $output);
        $loop->assertRun([
            new Timer(interval: 1, runAt: 1, scheduledAt: 0, scheduledBy: $this->functionName()),
            new Timer(interval: 20, runAt: 20, scheduledAt: 0, scheduledBy: $this->functionName()),
            new Timer(interval: 10, runAt: 30, scheduledAt: 20, scheduledBy: 'Watchtower\LaravelAgent\Ingest::write'),
        ]);
        $loop->assertPending([
            new Timer(interval: 3_600, runAt: 3_600, scheduledAt: 0, scheduledBy: 'Watchtower\LaravelAgent\IngestDetailsRepository::scheduleRefreshIn'),
        ]);
        $this->assertTrue($loop->running);
        $ingestDetailsBrowser->assertSent([
            Request::json('/api/agent-auth'),
        ]);
        $ingestBrowser->assertSent([
            Request::ingest([['t' => 'request']]),
        ]);
        $ingestDetailsBrowser->assertPending([]);
        $ingestBrowser->assertPending([]);
    }

    public function test_it_respects_the_quiet_flag(): void
    {
        $tokenHash = self::tokenHash();
        $loop = new LoopFake(runForSeconds: 40);
        $server = new TcpServerFake;
        $ingestDetailsBrowser = new BrowserFake([Response::jwt()]);
        $ingestBrowser = new BrowserFake([
            Response::ingested(),
        ]);

        $loop->addTimer(1, $server->pendingConnection(TcpServerFake::helloFrame('INVALID')));
        $loop->addTimer(20, $server->pendingConnection([['t' => 'request']]));

        [$output, $e] = $this->runAgent(
            via: 'source',
            ingestDetailsBrowser: $ingestDetailsBrowser,
            ingestBrowser: $ingestBrowser,
            loop: $loop,
            server: $server,
            quiet: true,
        );

        $this->assertNull($e, $e?->getMessage() ?? '');
        $server->assertOpen();
        $server->assertHandled([
            Connection::rejected(),
            Connection::ok(),
        ]);
        $this->assertLogMatches(<<<'OUTPUT'
        {date} {error} Incoming token hash mismatch! Check your application/agent configuration.
        OUTPUT, $output, true);
        $loop->assertRun([
            new Timer(interval: 1, runAt: 1, scheduledAt: 0, scheduledBy: $this->functionName()),
            new Timer(interval: 20, runAt: 20, scheduledAt: 0, scheduledBy: $this->functionName()),
            new Timer(interval: 10, runAt: 30, scheduledAt: 20, scheduledBy: 'Watchtower\LaravelAgent\Ingest::write'),
        ]);
        $loop->assertPending([
            new Timer(interval: 3_600, runAt: 3_600, scheduledAt: 0, scheduledBy: 'Watchtower\LaravelAgent\IngestDetailsRepository::scheduleRefreshIn'),
        ]);
        $this->assertTrue($loop->running);
        $ingestDetailsBrowser->assertSent([
            Request::json('/api/agent-auth'),
        ]);
        $ingestBrowser->assertSent([
            Request::ingest([['t' => 'request']]),
        ]);
        $ingestDetailsBrowser->assertPending([]);
        $ingestBrowser->assertPending([]);
    }

    public function test_it_respects_the_silent_flag(): void
    {
        $tokenHash = self::tokenHash();
        $loop = new LoopFake(runForSeconds: 40);
        $server = new TcpServerFake;
        $ingestDetailsBrowser = new BrowserFake([Response::jwt()]);
        $ingestBrowser = new BrowserFake([
            Response::ingested(),
        ]);

        $loop->addTimer(1, $server->pendingConnection(TcpServerFake::helloFrame('INVALID')));
        $loop->addTimer(20, $server->pendingConnection([['t' => 'request']]));

        [$output, $e] = $this->runAgent(
            via: 'source',
            ingestDetailsBrowser: $ingestDetailsBrowser,
            ingestBrowser: $ingestBrowser,
            loop: $loop,
            server: $server,
            silent: true,
        );

        $this->assertNull($e, $e?->getMessage() ?? '');
        $server->assertOpen();
        $server->assertHandled([
            Connection::rejected(),
            Connection::ok(),
        ]);
        $this->assertLogMatches('', $output, true);
        $loop->assertRun([
            new Timer(interval: 1, runAt: 1, scheduledAt: 0, scheduledBy: $this->functionName()),
            new Timer(interval: 20, runAt: 20, scheduledAt: 0, scheduledBy: $this->functionName()),
            new Timer(interval: 10, runAt: 30, scheduledAt: 20, scheduledBy: 'Watchtower\LaravelAgent\Ingest::write'),
        ]);
        $loop->assertPending([
            new Timer(interval: 3_600, runAt: 3_600, scheduledAt: 0, scheduledBy: 'Watchtower\LaravelAgent\IngestDetailsRepository::scheduleRefreshIn'),
        ]);
        $this->assertTrue($loop->running);
        $ingestDetailsBrowser->assertSent([
            Request::json('/api/agent-auth'),
        ]);
        $ingestBrowser->assertSent([
            Request::ingest([['t' => 'request']]),
        ]);
        $ingestDetailsBrowser->assertPending([]);
        $ingestBrowser->assertPending([]);
    }

    public function test_it_rejects_a_mismatched_session_id(): void
    {
        $this->assertProtocolRejected(
            TcpServerFake::helloFrame(self::tokenHash()).TcpServerFake::batchFrame([['t' => 'request']], sessionId: 'sess_wrong'),
            'session_mismatch',
        );
    }

    public function test_it_rejects_an_unexpected_sequence(): void
    {
        $this->assertProtocolRejected(
            TcpServerFake::helloFrame(self::tokenHash()).TcpServerFake::batchFrame([['t' => 'request']], sequence: 2),
            'sequence_mismatch',
        );
    }

    public function test_it_rejects_an_unsupported_batch_version(): void
    {
        $this->assertProtocolRejected(
            TcpServerFake::helloFrame(self::tokenHash()).TcpServerFake::batchFrame([['t' => 'request']], batchVersion: 99),
            'unsupported_batch_version',
        );
    }

    public function test_it_rejects_batches_larger_than_max_batch(): void
    {
        $this->assertProtocolRejected(
            TcpServerFake::helloFrame(self::tokenHash()).TcpServerFake::batchFrame(array_fill(0, 501, ['t' => 'request'])),
            'batch_too_large',
        );
    }

    public function test_an_unauthenticated_client_cannot_stop_the_sidecar(): void
    {
        $this->assertProtocolRejected(
            TcpServerFake::helloFrame('INVALID', protocolVersion: 99),
            'token_mismatch',
            '{date} {error} Incoming token hash mismatch! Check your application/agent configuration.',
        );
    }

    public function test_it_rejects_an_invalid_length_prefix_without_waiting(): void
    {
        $this->assertProtocolRejected(
            "abc\n{\"type\":\"hello\"}",
            'invalid_frame',
            '{date} {error} Connection error: Invalid frame received: Invalid Watchtower frame: length prefix must be a positive integer\.',
        );
    }

    public function test_it_rejects_frames_larger_than_the_limit(): void
    {
        $this->assertProtocolRejected(
            (Protocol::MAX_FRAME_BYTES + 1)."\n{",
            'invalid_frame',
            '{date} {error} Connection error: Invalid frame received: Invalid Watchtower frame: size \[\d+\] is out of range\.',
        );
    }

    public function test_it_rejects_records_that_are_not_a_list(): void
    {
        $this->assertProtocolRejected(
            TcpServerFake::helloFrame(self::tokenHash()).Frame::encode([
                'type' => Protocol::TYPE_TELEMETRY_BATCH,
                'protocol' => Protocol::NAME,
                'protocol_version' => Protocol::VERSION,
                'batch_version' => Protocol::BATCH_VERSION,
                'session_id' => 'sess_test',
                'sequence' => 1,
                'records' => ['t' => 'request'],
            ]),
            'invalid_records',
        );
    }

    public function test_it_closes_connections_that_stay_idle(): void
    {
        $loop = new LoopFake(runForSeconds: 10);
        $server = new TcpServerFake;
        $ingestDetailsBrowser = new BrowserFake([Response::jwt()]);
        $ingestBrowser = new BrowserFake;

        $loop->addTimer(1, new IdleConnection($server));

        [$output, $e] = $this->runAgent(
            via: 'source',
            ingestDetailsBrowser: $ingestDetailsBrowser,
            ingestBrowser: $ingestBrowser,
            loop: $loop,
            server: $server,
            connectionIdleTimeout: 5,
        );

        $this->assertNull($e, $e?->getMessage() ?? '');
        $server->assertOpen();
        $connection = $server->connections[0];
        $this->assertTrue($connection->closed);
        $this->assertSame('idle_timeout', Frame::decodeAll($connection->payload)[0]['code'] ?? null);
        $this->assertLogMatches(<<<'OUTPUT'
            {date} {info} Authentication successful {duration}
            {date} {error} Connection error: Connection idle for 5s; closed\.
            OUTPUT, $output);
    }

    private function assertProtocolRejected(string $wire, string $code, ?string $extraLog = null): void
    {
        $loop = new LoopFake(runForSeconds: 1);
        $server = new TcpServerFake;
        $ingestDetailsBrowser = new BrowserFake([Response::jwt()]);
        $ingestBrowser = new BrowserFake;

        $loop->addTimer(0, $server->pendingConnection($wire));

        [$output, $e] = $this->runAgent(
            via: 'source',
            ingestDetailsBrowser: $ingestDetailsBrowser,
            ingestBrowser: $ingestBrowser,
            loop: $loop,
            server: $server,
        );

        $this->assertNull($e, $e?->getMessage() ?? '');
        $server->assertHandled([
            Connection::rejected(),
        ]);
        $server->assertOpen();
        $messages = Frame::decodeAll($server->connections[0]->payload);
        $this->assertNotEmpty($messages);
        $last = $messages[count($messages) - 1];
        $this->assertSame($code, $last['code'] ?? null);
        $this->assertLogMatches(
            '{date} {info} Authentication successful {duration}'.($extraLog === null ? '' : "\n{$extraLog}"),
            $output,
        );
        $ingestBrowser->assertSent([]);
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use Watchtower\Laravel\Payload;
use Watchtower\Laravel\Transport\Protocol;

class PayloadTest extends TestCase
{
    public function test_it_can_determine_if_a_json_payload_is_empty(): void
    {
        $payload = Payload::json([], self::tokenHash());

        $this->assertTrue($payload->isEmpty());
    }

    #[DataProvider('textPayloads')]
    public function test_it_can_determine_if_a_text_payload_is_empty(string $value, bool $empty): void
    {
        $payload = Payload::text($value, 'tokenHash');

        $this->assertSame($empty, $payload->isEmpty());
    }

    public static function textPayloads(): iterable
    {
        yield ['', true];
        yield [' ', false];
        yield ['a', false];
    }

    public function test_it_exposes_json_records(): void
    {
        $payload = Payload::json([['t' => 'request']], self::tokenHash());

        $this->assertSame([['t' => 'request']], $payload->records());
        $this->assertFalse($payload->isPing());
    }

    public function test_it_identifies_ping_payloads(): void
    {
        $payload = Payload::text('PING', self::tokenHash());

        $this->assertTrue($payload->isPing());
        $this->assertSame([], $payload->records());
    }

    public function test_it_can_only_pull_the_payload_once(): void
    {
        $payload = Payload::json([['t' => 'request']], self::tokenHash());
        $payload->pullAs(['type' => 'telemetry_batch']);

        try {
            $payload->pullAs(['type' => 'telemetry_batch']);
            throw new RuntimeException;
        } catch (Throwable $e) {
            $this->assertSame('Payload has already been read', $e->getMessage());
        }
    }

    public function test_it_frees_memory_after_pulling_the_payload(): void
    {
        $payload = Payload::text('abc123', 'tokenHash');

        $this->assertSame('abc123', $payload->rawPayload());

        $payload->pullAs(['type' => 'ping']);
        $this->assertSame('', $payload->rawPayload());
    }

    public function test_it_has_up_to_date_protocol_version(): void
    {
        $this->assertSame(1, Payload::PROTOCOL_VERSION, 'Protocol version has changed! this indicates that a new major version must be tagged');
        $this->assertSame(Protocol::NAME, Payload::PROTOCOL);
    }
}

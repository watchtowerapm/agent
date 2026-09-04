<?php

namespace Tests\Unit;

use Tests\TestCase;
use Watchtower\LaravelAgent\Frame;
use Watchtower\LaravelAgent\FrameBuffer;
use Watchtower\LaravelAgent\Protocol;

use function substr;

class FrameBufferTest extends TestCase
{
    public function test_it_parses_a_complete_frame_in_one_append(): void
    {
        $buffer = new FrameBuffer;
        $buffer->append(Frame::encode([
            'type' => Protocol::TYPE_HELLO,
            'protocol' => Protocol::NAME,
            'protocol_version' => Protocol::VERSION,
            'token_hash' => 'a1b2c3d',
        ]));

        $message = $buffer->shift();

        $this->assertSame(Protocol::TYPE_HELLO, $message['type']);
        $this->assertSame('a1b2c3d', $message['token_hash']);
        $this->assertTrue($buffer->isEmpty());
    }

    public function test_it_parses_frames_incrementally(): void
    {
        $wire = Frame::encode(['type' => Protocol::TYPE_PING, 'sequence' => 1]);
        $buffer = new FrameBuffer;

        $buffer->append(substr($wire, 0, 3));
        $this->assertNull($buffer->shift());
        $this->assertFalse($buffer->isEmpty());

        $buffer->append(substr($wire, 3));
        $this->assertSame(Protocol::TYPE_PING, $buffer->shift()['type']);
        $this->assertTrue($buffer->isEmpty());
    }

    public function test_it_can_parse_two_frames_from_one_chunk(): void
    {
        $buffer = new FrameBuffer;
        $buffer->append(
            Frame::encode(['type' => Protocol::TYPE_HELLO, 'token_hash' => 'abc']).
            Frame::encode(['type' => Protocol::TYPE_PING, 'sequence' => 4])
        );

        $hello = $buffer->shift();
        $ping = $buffer->shift();

        $this->assertSame(Protocol::TYPE_HELLO, $hello['type']);
        $this->assertSame(Protocol::TYPE_PING, $ping['type']);
        $this->assertSame(4, $ping['sequence']);
        $this->assertNull($buffer->shift());
    }
}

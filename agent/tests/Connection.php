<?php

namespace Tests;

use Evenement\EventEmitter;
use PHPUnit\Framework\Assert;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;
use RuntimeException;
use Watchtower\LaravelAgent\Frame;
use Watchtower\LaravelAgent\Protocol;

use function count;

class Connection extends EventEmitter implements ConnectionInterface
{
    public function __construct(
        public string $payload = '',
        public bool $closed = false,
        public string $kind = 'raw',
    ) {
        //
    }

    public static function ok(): self
    {
        return new self('', closed: true, kind: 'ok');
    }

    public static function rejected(): self
    {
        return new self('', closed: true, kind: 'error');
    }

    public static function closed(
        string $payload = '',
    ): self {
        return new self($payload, closed: true);
    }

    public function assertMatches(self $expected): void
    {
        Assert::assertSame($expected->closed, $this->closed);

        if ($expected->kind === 'ok') {
            $messages = Frame::decodeAll($this->payload);
            Assert::assertGreaterThanOrEqual(2, count($messages), $this->payload);
            Assert::assertSame(Protocol::TYPE_WELCOME, $messages[0]['type'] ?? null);
            Assert::assertTrue($messages[0]['accepted'] ?? false);
            Assert::assertSame(Protocol::TYPE_ACK, $messages[1]['type'] ?? null);

            return;
        }

        if ($expected->kind === 'error') {
            $messages = Frame::decodeAll($this->payload);
            Assert::assertNotEmpty($messages);
            $last = $messages[count($messages) - 1];
            Assert::assertSame(Protocol::TYPE_ERROR, $last['type'] ?? null, $this->payload);

            return;
        }

        Assert::assertSame($expected->payload, $this->payload);
    }

    public function getRemoteAddress()
    {
        throw new RuntimeException(__FUNCTION__);
    }

    public function getLocalAddress()
    {
        throw new RuntimeException(__FUNCTION__);
    }

    public function isReadable()
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

    /**
     * @param  array<mixed>  $options
     */
    public function pipe(WritableStreamInterface $dest, array $options = [])
    {
        throw new RuntimeException(__FUNCTION__);
    }

    public function close()
    {
        $this->closed = true;
    }

    public function isWritable()
    {
        throw new RuntimeException(__FUNCTION__);
    }

    public function write($data)
    {
        if (! $this->closed) {
            $this->payload .= (string) $data; // @phpstan-ignore cast.string

            return true;
        }

        return false;
    }

    public function end($data = null)
    {
        if ($this->closed) {
            return;
        }

        if ($data) {
            $this->write($data);
        }

        $this->close();
    }
}

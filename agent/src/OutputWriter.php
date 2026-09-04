<?php

namespace Watchtower\LaravelAgent;

use React\Stream\WritableResourceStream;

use function fflush;
use function WatchtowerAgent\fwrite_all;

class OutputWriter
{
    /**
     * @param  resource  $syncStream
     */
    public function __construct(
        private Loop $loop,
        private $syncStream,
        private ?WritableResourceStream $asyncStream,
    ) {
        //
    }

    public function write(string $message): void
    {
        if ($this->loop->running() && $this->asyncStream !== null) {
            $this->asyncStream->write($message);
        } else {
            fwrite_all($this->syncStream, $message);
            fflush($this->syncStream);
        }
    }
}

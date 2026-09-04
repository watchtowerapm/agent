<?php

namespace Watchtower\LaravelAgent;

class IngestDetails
{
    public function __construct(
        public string $token,
        public int $expiresIn,
        public string $ingestUrl,
        public int $refreshIn,
        public int $expiresAt,
    ) {
        //
    }
}

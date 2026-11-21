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
        /** @var array<mixed>|null Sampling and filtering chosen in the panel (null from platforms that don't send them). */
        public ?array $settings = null,
    ) {
        //
    }
}

<?php

namespace Tests\Integration;

use Tests\TestCase;

use function str_contains;

class PharTest extends TestCase
{
    public function test_it_can_start_the_agent_and_authenticate(): void
    {
        $baseUrl = self::baseUrl();

        if ($baseUrl === '' || str_contains($baseUrl, 'watchtower.test')) {
            $this->markTestSkipped('Requires a reachable Watchtower instance (WATCHTOWER_BASE_URL).');
        }

        [$output, $e] = $this->runAgent(via: 'phar', timeout: 10, until: static fn ($output) => str_contains($output, 'Authentication'));

        $this->assertNull($e, $e?->getMessage() ?? '');
        $this->assertLogMatches(<<<'OUTPUT'
        {date} {info} Authentication successful {duration}
        OUTPUT, $output);
    }
}

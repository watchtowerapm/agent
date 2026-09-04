<?php

namespace Tests\Unit;

use Tests\TestCase;
use Watchtower\Laravel\Transport\Protocol as PackageProtocol;
use Watchtower\LaravelAgent\Protocol as AgentProtocol;

require __DIR__.'/../../agent/src/Protocol.php';

class PayloadVersionTest extends TestCase
{
    public function test_that_protocol_versions_match(): void
    {
        $this->assertSame(PackageProtocol::VERSION, AgentProtocol::VERSION, 'Package protocol version must match Agent protocol version, changing this indicates that a new major version must be tagged');
        $this->assertSame(PackageProtocol::NAME, AgentProtocol::NAME);
    }
}

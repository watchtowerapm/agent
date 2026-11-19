<?php

namespace Tests\Unit;

use Tests\SyncedClock;
use Tests\TestCase;
use Watchtower\LaravelAgent\Spool;

use function glob;
use function is_dir;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class SpoolTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/watchtower-spool-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->directory}/*") ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_it_returns_payloads_oldest_first(): void
    {
        $spool = new Spool($this->directory, 1_000, 3_600, new SyncedClock(1_000));

        $this->assertTrue($spool->isEmpty());
        $this->assertTrue($spool->put('first'));
        $this->assertTrue($spool->put('second'));
        $this->assertSame(2, $spool->count());

        [$path, $payload] = $spool->oldest() ?? ['', ''];
        $this->assertSame('first', $payload);

        $spool->remove($path);
        $this->assertSame('second', $spool->oldest()[1] ?? null);
    }

    public function test_it_drops_the_oldest_payloads_when_over_the_size_cap(): void
    {
        $spool = new Spool($this->directory, 25, 3_600, new SyncedClock(1_000));

        $spool->put(str_repeat('a', 10));
        $spool->put(str_repeat('b', 10));
        $spool->put(str_repeat('c', 10));

        $this->assertSame(2, $spool->count());
        $this->assertSame(str_repeat('b', 10), $spool->oldest()[1] ?? null);
    }

    public function test_it_drops_payloads_older_than_the_maximum_age(): void
    {
        $clock = new SyncedClock(1_000);
        $spool = new Spool($this->directory, 1_000, 60, $clock);

        $spool->put('old');
        $clock->now = 1_100;
        $spool->put('new');

        $this->assertSame(1, $spool->count());
        $this->assertSame('new', $spool->oldest()[1] ?? null);
    }

    public function test_it_refuses_a_payload_larger_than_the_whole_spool(): void
    {
        $spool = new Spool($this->directory, 5, 3_600, new SyncedClock(1_000));

        $this->assertFalse($spool->put('too large'));
        $this->assertTrue($spool->isEmpty());
    }
}

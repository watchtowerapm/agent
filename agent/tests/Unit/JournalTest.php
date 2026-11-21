<?php

namespace Tests\Unit;

use Tests\TestCase;
use Watchtower\LaravelAgent\Journal;

use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class JournalTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/watchtower-journal-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->directory}/journal-*") ?: [] as $journal) {
            foreach (glob("{$journal}/*") ?: [] as $file) {
                unlink($file);
            }

            rmdir($journal);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_batches_are_on_disk_until_their_upload_is_done(): void
    {
        $journal = new Journal($this->directory);

        $this->assertTrue($journal->append('[{"t":"request"}]'));
        $this->assertTrue($journal->append('[{"t":"query"}]'));
        $sealed = $journal->seal();

        $this->assertNotNull($sealed);
        $this->assertSame("[{\"t\":\"request\"}]\n[{\"t\":\"query\"}]\n", file_get_contents($sealed));
        $this->assertNull($journal->seal(), 'Nothing new was written since the last seal.');

        $journal->release($sealed);

        $this->assertFileDoesNotExist($sealed);
    }

    public function test_it_recovers_batches_a_stopped_sidecar_had_acknowledged(): void
    {
        $crashed = new Journal($this->directory);
        $crashed->append('[{"t":"request"}]');
        $crashed->seal();
        $crashed->append('[{"t":"query"},{"t":"cache-event"}]');
        // The process dies: its lock goes with it.
        unset($crashed);

        $payloads = (new Journal($this->directory))->recover();

        $this->assertSame([
            '{"records":[{"t":"request"}]}',
            '{"records":[{"t":"query"},{"t":"cache-event"}]}',
        ], $payloads);
        $this->assertSame([], glob("{$this->directory}/journal-*") ?: []);
    }

    public function test_it_leaves_a_running_sidecars_journal_alone(): void
    {
        $running = new Journal($this->directory);
        $running->append('[{"t":"request"}]');

        $this->assertSame([], (new Journal($this->directory))->recover());

        $this->assertNotNull($running->seal());
    }

    public function test_it_skips_a_batch_cut_off_by_a_crash(): void
    {
        $crashed = new Journal($this->directory);
        $crashed->append('[{"t":"request"}]');
        unset($crashed);
        [$journal] = glob("{$this->directory}/journal-*") ?: [];
        file_put_contents("{$journal}/current", '[{"t":"que', FILE_APPEND);

        $this->assertSame(['{"records":[{"t":"request"}]}'], (new Journal($this->directory))->recover());
    }

    public function test_discarding_drops_what_was_not_yet_sealed(): void
    {
        $journal = new Journal($this->directory);
        $journal->append('[{"t":"request"}]');
        $journal->discard();
        unset($journal);

        $this->assertSame([], (new Journal($this->directory))->recover());
    }
}

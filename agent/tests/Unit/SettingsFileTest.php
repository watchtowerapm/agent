<?php

namespace Tests\Unit;

use Tests\TestCase;
use Watchtower\LaravelAgent\SettingsFile;

use function file_put_contents;
use function filemtime;
use function is_file;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

class SettingsFileTest extends TestCase
{
    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/wt-settings-'.uniqid();
        mkdir($this->directory);
        $this->path = $this->directory.'/watchtower-settings.php';
    }

    public function test_it_writes_valid_settings_for_the_app_and_ignores_the_rest(): void
    {
        $file = new SettingsFile($this->path);

        $this->assertTrue($file->apply([
            'sampling' => ['requests' => 0.1, 'commands' => 1, 'exceptions' => 7, 'unknown' => 0.5],
            'filtering' => ['ignore_queries' => true, 'ignore_mail' => 'yes'],
            'evil' => ['system' => 'rm -rf /'],
        ]));

        $this->assertSame([
            'sampling' => ['requests' => 0.1, 'commands' => 1.0],
            'filtering' => ['ignore_queries' => true],
        ], require $this->path);
    }

    public function test_it_only_rewrites_when_the_settings_change(): void
    {
        $file = new SettingsFile($this->path);

        $this->assertTrue($file->apply(['sampling' => ['requests' => 0.5]]));
        $this->assertFalse($file->apply(['sampling' => ['requests' => 0.5]]));
        $this->assertTrue($file->apply(['sampling' => ['requests' => 0.25]]));
        $this->assertSame(['sampling' => ['requests' => 0.25]], require $this->path);
    }

    public function test_no_settings_remove_the_file_so_the_apps_own_config_applies(): void
    {
        file_put_contents($this->path, '<?php return ["sampling" => ["requests" => 0.5]];');
        $file = new SettingsFile($this->path);

        $this->assertTrue($file->apply([]));
        $this->assertFalse(is_file($this->path));
    }

    public function test_a_missing_directory_is_not_an_error(): void
    {
        $file = new SettingsFile($this->directory.'/missing/watchtower-settings.php');

        $this->assertFalse($file->apply(['sampling' => ['requests' => 0.5]]));
        $this->assertNotFalse(filemtime($this->directory));
    }
}

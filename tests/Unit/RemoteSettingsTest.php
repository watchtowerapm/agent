<?php

namespace Tests\Unit;

use Tests\TestCase;
use Watchtower\Laravel\RemoteSettings;

use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class RemoteSettingsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/wt-remote-'.uniqid().'.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    public function test_the_panels_settings_override_the_apps_config(): void
    {
        file_put_contents($this->path, '<?php return ["sampling" => ["requests" => 0.1], "filtering" => ["ignore_queries" => true]];');

        $config = RemoteSettings::merge([
            'sampling' => ['requests' => 1.0, 'commands' => 0.5],
            'filtering' => ['ignore_queries' => false, 'ignore_mail' => true],
        ], $this->path);

        $this->assertSame(0.1, $config['sampling']['requests']);
        $this->assertSame(0.5, $config['sampling']['commands'], 'settings the panel leaves alone keep the app value');
        $this->assertTrue($config['filtering']['ignore_queries']);
        $this->assertTrue($config['filtering']['ignore_mail']);
    }

    public function test_without_a_file_or_when_turned_off_the_app_config_is_used(): void
    {
        $config = ['sampling' => ['requests' => 1.0]];

        $this->assertSame($config, RemoteSettings::merge($config, $this->path));

        file_put_contents($this->path, '<?php return ["sampling" => ["requests" => 0.1]];');
        $this->assertSame(1.0, RemoteSettings::merge(['remote_settings' => false] + $config, $this->path)['sampling']['requests']);
    }

    public function test_a_broken_file_never_breaks_the_app(): void
    {
        file_put_contents($this->path, '<?php return "nonsense";');
        $config = ['sampling' => ['requests' => 1.0]];

        $this->assertSame($config, RemoteSettings::merge($config, $this->path));
    }
}

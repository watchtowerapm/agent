<?php

namespace Tests\Unit;

use ReflectionMethod;
use Tests\TestCase;
use Watchtower\Laravel\Console\AgentCommand;

use function config;
use function getenv;
use function putenv;

/**
 * `php artisan optimize` caches the config, after which Laravel no longer loads `.env`. The agent
 * reads its settings from the process environment, so the command copies them from the config.
 */
class AgentCommandConfigTest extends TestCase
{
    private const KEYS = ['WATCHTOWER_BASE_URL', 'WATCHTOWER_AGENT_LOG_LEVEL', 'WATCHTOWER_SPOOL_PATH', 'WATCHTOWER_SPOOL_MAX_MB', 'WATCHTOWER_SPOOL_MAX_AGE_HOURS'];

    /** @var array<string, mixed> */
    private array $server = [];

    /** @var array<string, mixed> */
    private array $envSuperglobal = [];

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            $this->server[$key] = $_SERVER[$key] ?? null;
            $this->envSuperglobal[$key] = $_ENV[$key] ?? null;
            $this->env[$key] = getenv($key);
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            unset($_SERVER[$key], $_ENV[$key]);
            if ($this->server[$key] !== null) {
                $_SERVER[$key] = $this->server[$key];
            }
            if ($this->envSuperglobal[$key] !== null) {
                $_ENV[$key] = $this->envSuperglobal[$key];
            }
            putenv($this->env[$key] === false ? $key : "{$key}={$this->env[$key]}");
        }

        parent::tearDown();
    }

    public function test_settings_only_in_the_config_reach_the_agent(): void
    {
        config([
            'watchtower.base_url' => 'https://api.watchtowers.cloud',
            'watchtower.agent.log_level' => 'verbose',
            'watchtower.agent.spool_path' => '',
            'watchtower.agent.spool_max_mb' => 50,
        ]);

        $this->expose();

        $this->assertSame('https://api.watchtowers.cloud', $_SERVER['WATCHTOWER_BASE_URL']);
        $this->assertSame('verbose', $_SERVER['WATCHTOWER_AGENT_LOG_LEVEL']);
        $this->assertSame('', $_SERVER['WATCHTOWER_SPOOL_PATH'], 'an empty spool path still turns the spool off');
        $this->assertSame('50', $_SERVER['WATCHTOWER_SPOOL_MAX_MB']);
        $this->assertArrayNotHasKey('WATCHTOWER_SPOOL_MAX_AGE_HOURS', $_SERVER, 'unset settings stay unset');
    }

    public function test_the_real_environment_wins(): void
    {
        config(['watchtower.base_url' => 'https://from-config.example']);
        putenv('WATCHTOWER_BASE_URL=https://from-supervisor.example');

        $this->expose();

        $this->assertArrayNotHasKey('WATCHTOWER_BASE_URL', $_SERVER);
        $this->assertSame('https://from-supervisor.example', getenv('WATCHTOWER_BASE_URL'));
    }

    public function test_the_platform_is_the_default_destination(): void
    {
        $config = require __DIR__.'/../../config/watchtower.php';

        $this->assertSame('https://api.watchtowers.cloud', $config['base_url']);

        putenv('WATCHTOWER_BASE_URL=https://staging.example');
        $config = require __DIR__.'/../../config/watchtower.php';

        $this->assertSame('https://staging.example', $config['base_url'], 'an override wins');
    }

    private function expose(): void
    {
        $method = new ReflectionMethod(AgentCommand::class, 'exposeConfigToAgent');
        $method->invoke($this->app->make(AgentCommand::class));
    }
}

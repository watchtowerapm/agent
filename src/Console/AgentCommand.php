<?php

namespace Watchtower\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Watchtower\Laravel\GracefulCliOutputExceptionHandler;
use Watchtower\Laravel\RemoteSettings;

use function config;
use function getenv;
use function is_file;

/**
 * @internal
 */
#[AsCommand(name: 'watchtower:agent', description: 'Run the Watchtower agent.')]
final class AgentCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'watchtower:agent
        {--listen-on=}
        {--auth-connection-timeout=}
        {--auth-timeout=}
        {--ingest-connection-timeout=}
        {--ingest-timeout=}
        {--server=}
        {--silent : Do not output any message}';

    /**
     * @var string
     */
    protected $description = 'Run the Watchtower agent.';

    public function __construct(
        #[SensitiveParameter] private ?string $token,
        private ?string $server,
        private ?string $ingestUri,
    ) {
        parent::__construct();
    }

    public function handle(Application $app): void
    {
        try {
            $handler = $app->instance(
                ExceptionHandler::class,
                new GracefulCliOutputExceptionHandler($app->make(ExceptionHandler::class))
            );
        } catch (Throwable) {
            //
        }

        $this->exposeConfigToAgent();

        $refreshToken = $this->token;

        $listenOn = $this->option('listen-on') ?? $this->ingestUri;

        $authenticationConnectionTimeout = $this->option('auth-connection-timeout');

        $authenticationTimeout = $this->option('auth-timeout');

        $ingestConnectionTimeout = $this->option('ingest-connection-timeout');

        $ingestTimeout = $this->option('ingest-timeout');

        $server = $this->option('server') ?? $this->server;

        $silent = $this->option('silent') ?: null;

        $quiet = $this->option('quiet') ?: null;

        $verbose = $this->option('verbose') ?: null;

        // Where the panel's sampling settings go for the app to read at boot.
        $settingsPath = config('watchtower.remote_settings', true) ? RemoteSettings::path($app) : null;

        $phar = __DIR__.'/../../agent/build/agent.phar';
        $source = __DIR__.'/../../agent/src/agent.php';
        require is_file($phar) ? $phar : $source;

        if (isset($handler)) {
            $handler->shuttingDown();
        }
    }

    /**
     * The agent reads its settings from the process environment. With a cached config
     * (`php artisan optimize` / `config:cache`) Laravel no longer loads `.env`, so the values only
     * exist in the config: copy them over. Variables already set in the environment (shell,
     * Supervisor, Docker) keep priority.
     */
    private function exposeConfigToAgent(): void
    {
        $settings = [
            'WATCHTOWER_BASE_URL' => config('watchtower.base_url'),
            'WATCHTOWER_AGENT_LOG_LEVEL' => config('watchtower.agent.log_level'),
            'WATCHTOWER_SPOOL_PATH' => config('watchtower.agent.spool_path'),
            'WATCHTOWER_SPOOL_MAX_MB' => config('watchtower.agent.spool_max_mb'),
            'WATCHTOWER_SPOOL_MAX_AGE_HOURS' => config('watchtower.agent.spool_max_age_hours'),
        ];

        foreach ($settings as $name => $value) {
            if ($value === null || isset($_SERVER[$name]) || getenv($name) !== false) {
                continue;
            }

            // An empty spool path is meaningful (it turns the spool off), so strings are kept as is.
            $_SERVER[$name] = (string) $value; // @phpstan-ignore cast.string
        }
    }
}

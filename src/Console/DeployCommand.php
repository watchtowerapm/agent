<?php

namespace Watchtower\Laravel\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

use function config;
use function is_string;

/**
 * @internal
 */
#[AsCommand(name: 'watchtower:deploy', description: 'Send deployment metadata to Watchtower.')]
final class DeployCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'watchtower:deploy
        {deploy? : A unique value for the deploy <comment>[default: `WATCHTOWER_DEPLOY`]</comment>}
        {--ref= : The git ref (tag or hash) of the deploy}
        {--name= : The human-readable name of the deploy}
        {--url= : A URL with information related to the deploy}
        {--timestamp= : The timestamp of the deploy <comment>[default: `now()`]</comment>}';

    /**
     * @var string
     */
    protected $description = 'Send deployment metadata to Watchtower.';

    public function __construct(
        #[SensitiveParameter] private ?string $token,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $timestamp = is_string($this->option('timestamp')) ? CarbonImmutable::parse($this->option('timestamp')) : CarbonImmutable::now();

        $deploy = $this->argument('deploy') ?? config('watchtower.deployment');

        if (! $deploy) {
            $this->components->error('Please configure the [WATCHTOWER_DEPLOY] environment variable.');

            return 0;
        }

        if (! $this->token) {
            $this->components->error('Please configure the [WATCHTOWER_TOKEN] environment variable.');

            return 0;
        }

        $baseUrl = $_SERVER['WATCHTOWER_BASE_URL'] ?? '';
        if ($baseUrl === '') {
            $this->components->error('Please configure the [WATCHTOWER_BASE_URL] environment variable to your Watchtower platform origin.');

            return 0;
        }

        try {
            Http::connectTimeout(5)
                ->timeout(10)
                ->acceptJson()
                ->withToken($this->token)
                ->post("{$baseUrl}/api/deployments", [
                    'timestamp' => $timestamp->utc()->toDateTimeString('microsecond'),
                    'deploy' => $deploy,
                    'ref' => $this->option('ref'),
                    'name' => $this->option('name'),
                    'url' => $this->option('url'),
                ])
                ->throw();

            $this->components->info('Deployment sent to Watchtower successfully.');
        } catch (RequestException $e) {
            $message = Str::limit($e->response->json('message') ?? "[{$e->getCode()}] {$e->response->body()}", 1000, '[...]'); // @phpstan-ignore argument.type

            $this->components->error("Deployment could not be sent to Watchtower: {$message}");
        } catch (Throwable $e) {
            $this->components->error("Deployment could not be sent to Watchtower: {$e->getMessage()}");
        }

        return 0;
    }
}

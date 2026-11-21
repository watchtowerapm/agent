<?php

namespace Watchtower\LaravelAgent;

use Closure;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop as BaseLoop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ServerInterface;
use React\Socket\TcpServer;
use React\Stream\WritableResourceStream;
use Watchtower\LaravelAgent\Contracts\Browser;
use Watchtower\LaravelAgent\Contracts\Clock as ClockContract;
use Watchtower\LaravelAgent\Factories\BrowserFactory;

use function date;
use function dirname;
use function file_get_contents;
use function getenv;
use function gethostname;
use function hash;
use function is_file;
use function is_string;
use function preg_replace;
use function realpath;
use function round;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function sys_get_temp_dir;

require __DIR__.'/bootstrap.php';

/*
 * Testing...
 */

/** @var (Closure(float $connectionTimeout, float $timeout, array<string, string> $headers, ?string $baseUrl): Browser)|null $browserFactory */
$browserFactory ??= null;
/** @var (Closure(): ServerInterface)|null $serverResolver */
$serverResolver ??= null;
/** @var ?LoopInterface $loop */
$loop ??= null;
/** @var ?ClockContract $clock */
$clock ??= null;
/** @var ?int $maxBufferLength */
$maxBufferLength ??= null;
/** @var ?float $connectionIdleTimeout Seconds before an unfinished local connection is closed; 0 disables. */
$connectionIdleTimeout ??= (float) Protocol::IDLE_TIMEOUT_SECONDS;

/*
 * Input...
 */

/** @var ?string $refreshToken */
$refreshToken ??= $_SERVER['WATCHTOWER_TOKEN'] ?? getenv('WATCHTOWER_TOKEN') ?: '';
/** @var string $refreshToken */
/** @var ?string $baseUrl */
// The Watchtower platform; WATCHTOWER_BASE_URL overrides it (self-hosted or staging platforms).
$baseUrl ??= $_SERVER['WATCHTOWER_BASE_URL'] ?? getenv('WATCHTOWER_BASE_URL') ?: 'https://api.watchtowers.cloud';
/** @var string $baseUrl */
/** @var ?string $settingsPath Where to write the panel's sampling settings for the app; null or '' turns it off. */
$settingsPath ??= $_SERVER['WATCHTOWER_SETTINGS_PATH'] ?? getenv('WATCHTOWER_SETTINGS_PATH') ?: null;
/** @var ?string $listenOn */
$listenOn ??= $_SERVER['WATCHTOWER_INGEST_URI'] ?? '127.0.0.1:2407';
/** @var string $listenOn */
/** @var ?float $authenticationConnectionTimeout */
$authenticationConnectionTimeout ??= 5; // @phpstan-ignore varTag.nativeType
/** @var ?float $authenticationTimeout */
$authenticationTimeout ??= 10; // @phpstan-ignore varTag.nativeType
/** @var ?float $ingestConnectionTimeout */
$ingestConnectionTimeout ??= 5; // @phpstan-ignore varTag.nativeType
/** @var ?float $ingestTimeout */
$ingestTimeout ??= 10; // @phpstan-ignore varTag.nativeType
// Where undelivered payloads wait until Watchtower is reachable; '' turns the spool off.
$spoolEnv = static fn (string $key): string => (string) ($_SERVER[$key] ?? getenv($key) ?: ''); // @phpstan-ignore cast.string
/** @var ?string $spoolPath */
$spoolPath ??= $spoolEnv('WATCHTOWER_SPOOL_PATH') ?: sys_get_temp_dir().'/watchtower-agent-spool-'.substr(hash('xxh128', $refreshToken), 0, 12); // @phpstan-ignore varTag.nativeType
/** @var string $spoolPath */
$spoolMaxBytes = (int) ($spoolEnv('WATCHTOWER_SPOOL_MAX_MB') ?: 100) * 1024 * 1024;
$spoolMaxAge = (int) ($spoolEnv('WATCHTOWER_SPOOL_MAX_AGE_HOURS') ?: 24) * 3600;
/** @var ?string $server */
$server ??= (string) gethostname(); // @phpstan-ignore varTag.nativeType
/** @var ?bool $silent */
$silent ??= strtolower($_SERVER['WATCHTOWER_AGENT_LOG_LEVEL'] ?? '') === 'critical'; // @phpstan-ignore argument.type, varTag.nativeType
/** @var ?bool $quiet */
$quiet ??= strtolower($_SERVER['WATCHTOWER_AGENT_LOG_LEVEL'] ?? '') === 'error'; // @phpstan-ignore argument.type, varTag.nativeType
/** @var ?bool $verbose */
$verbose ??= strtolower($_SERVER['WATCHTOWER_AGENT_LOG_LEVEL'] ?? '') === 'verbose'; // @phpstan-ignore argument.type, varTag.nativeType

/*
 * Prepare loop...
 */

$loop = new Loop($loop ?? new StreamSelectLoop);
BaseLoop::set($loop);

/*
 * Logging helpers...
 */

[$asyncStdOut, $asyncStdError] = match (PHP_OS_FAMILY) {
    'Windows' => [null, null],
    default => [new WritableResourceStream(STDOUT), new WritableResourceStream(STDERR)],
};

$stdOut = new OutputWriter($loop, syncStream: STDOUT, asyncStream: $asyncStdOut);
$stdErr = new OutputWriter($loop, syncStream: STDERR, asyncStream: $asyncStdError);

$debug = static function (string $message) use ($verbose, $stdOut): void {
    if ($verbose) {
        $stdOut->write(date('Y-m-d H:i:s').' [DEBUG] '.$message.PHP_EOL);
    }
};
$info = static function (string $message) use ($silent, $quiet, $stdOut): void {
    if (! $quiet && ! $silent) {
        $stdOut->write(date('Y-m-d H:i:s').' [INFO] '.$message.PHP_EOL);
    }
};
$error = static function (string $message) use ($silent, $stdErr): void {
    if (! $silent) {
        $stdErr->write(date('Y-m-d H:i:s').' [ERROR] '.$message.PHP_EOL);
    }
};

/*
 * Internal state...
 */

$tokenHash = substr(hash('xxh128', $refreshToken), 0, 7);
$sessionKey = Mac::key($refreshToken);
$fromPhar = str_starts_with(__DIR__, 'phar://');
/** @var ?string $basePath */
if ($fromPhar) {
    $basePath ??= str_replace(['phar://', '/agent.phar/src'], '', __DIR__);
    $versionFile = $basePath.'/../../version.txt';
    $envoyerPath = preg_replace('#^(.*?)/releases/\d+/(.*)$#', '$1/current/$2', $basePath) ?? '';

    if (is_file($envoyerPath.'/signature.txt') && realpath($envoyerPath) === $basePath) {
        $signaturePath = $envoyerPath.'/signature.txt';
    } else {
        $signaturePath = $basePath.'/signature.txt';
    }
} else {
    $basePath ??= dirname(__DIR__);
    $versionFile = $basePath.'/../version.txt';
    $signaturePath = $basePath.'/signature.txt';
}

$debug("Reading signature from [{$signaturePath}]");

$expectedSignature = @file_get_contents($signaturePath);

if ($expectedSignature === false) {
    $error('Unable to read the signature');

    return;
}

$debug('Found signature ['.rtrim($expectedSignature).']');

if (! is_file($versionFile)) {
    $versionFile = dirname(__DIR__).'/../version.txt';
}

$packageVersion = rtrim((string) @file_get_contents($versionFile));

/*
 * Initialize services...
 */
$clock ??= new Clock;

$browserFactory ??= new BrowserFactory;

$ingestDetailsBrowser = $browserFactory(
    connectionTimeout: $authenticationConnectionTimeout,
    timeout: $authenticationTimeout,
    headers: [
        'accept' => 'application/json',
        'authorization' => "Bearer {$refreshToken}",
        'content-type' => 'application/json',
        'watchtower-server' => $server,
        'user-agent' => 'WatchtowerAgent/'.$packageVersion,
    ],
    baseUrl: rtrim($baseUrl, '/'),
);

$settingsFile = is_string($settingsPath) && $settingsPath !== '' ? new SettingsFile($settingsPath) : null;

$ingestDetails = new IngestDetailsRepository(
    loop: $loop,
    browser: $ingestDetailsBrowser,
    clock: $clock,
    onAuthenticationSuccess: static function (IngestDetails $ingestDetails, float $duration) use ($info, $settingsFile): void {
        $info('Authentication successful ['.round($duration, 3).'s]');

        // Old platforms don't send settings: leave the app's file alone then.
        if ($settingsFile !== null && $ingestDetails->settings !== null && $settingsFile->apply($ingestDetails->settings)) {
            $info('Sampling settings from the panel updated');
        }
    },
    onAuthenticationError: static fn (string $message, float $duration) => $error('Authentication failed ['.round($duration, 3).'s]: '.$message),
    onUnderQuota: static function () use (&$ingest) {
        /** @var Ingest $ingest */
        $ingest->resumeIngestion();
    },
);

$ingestBrowser = $browserFactory(
    connectionTimeout: $ingestConnectionTimeout,
    timeout: $ingestTimeout,
    headers: [
        'accept' => 'application/json',
        'content-encoding' => 'gzip',
        'content-type' => 'application/json',
        'watchtower-server' => $server,
        'user-agent' => 'WatchtowerAgent/'.$packageVersion,
    ],
);

$ingest = new Ingest(
    loop: $loop,
    browser: $ingestBrowser,
    ingestDetails: $ingestDetails,
    clock: $clock,
    buffer: new StreamBuffer($maxBufferLength ?? 6_000_000),
    concurrentRequestLimit: 5,
    maxBufferDurationInSeconds: 10,
    onIngestSuccess: static fn (ResponseInterface $response, float $duration) => $info('Ingest successful ['.round($duration, 3).'s]'),
    onIngestError: static fn (string $message, float $duration) => $error('Ingest failed ['.round($duration, 3).'s]: '.$message),
    onOverQuota: static fn (string $message, float $duration) => $error('Ingest attempted ['.round($duration, 3).'s]: '.$message),
    spool: $spoolPath === '' ? null : new Spool($spoolPath, $spoolMaxBytes, $spoolMaxAge, $clock),
    onSpooled: static fn (int $waiting) => $info("Uploads busy: kept the payload to send later [{$waiting} waiting]"),
);

$server = new Server(
    serverResolver: $serverResolver ?? static fn (): ServerInterface => new TcpServer($listenOn),
    key: $sessionKey,
    tokenHash: $tokenHash,
    onServerStarted: static fn () => $info("Watchtower agent initiated: Listening on [{$listenOn}]; Version [{$packageVersion}]"),
    onServerError: static fn (string $message) => $error("Server error: {$message}"),
    onConnectionError: static fn (string $message) => $error("Connection error: {$message}"),
    onPayloadReceived: $ingest->write(...),
    onInvalidPayloadVersion: static function () use ($info, $loop, $ingest) {
        $info('Incoming payload version has changed');

        $ingest->forceDigest()->finally(static function () use ($info, $loop) {
            $loop->stop();

            $info('Shutting down');
        });
    },
    onInvalidTokenHash: static fn () => $error('Incoming token hash mismatch! Check your application/agent configuration.'),
    loop: $loop,
    idleTimeout: $connectionIdleTimeout,
    onOutdatedClient: static fn () => $error('Refused data from an application still running an older Watchtower agent. Restart PHP-FPM, Octane and your queue workers (php artisan queue:restart) so they load the new version.'),
);

$checkSignature = new CheckSignature(
    loop: $loop,
    signaturePath: $signaturePath,
    expectedSignature: $expectedSignature,
    shutdownDelayInMinutes: 5,
    onCheckSignature: static function ($signature) use ($debug) {
        $debug('Signature checked: ['.rtrim($signature).']');
    },
    onShutdownInitiated: static function ($shuttingDownIn) use ($info) {
        $info('Agent signature changed: shutting down in '.$shuttingDownIn.' minutes');
    },
    onShutdown: static function () use ($info, $loop, $ingest) {
        $ingest->forceDigest()->finally(static function () use ($info, $loop) {
            $loop->stop();

            $info('Shutting down');
        });
    },
);

/*
 * Get things rolling...
 */

$server->start();

$ingestDetails->hydrate();

$checkSignature->start();

$loop->run();

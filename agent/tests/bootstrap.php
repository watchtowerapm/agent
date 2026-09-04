<?php

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidPathException;

if (! ($_SERVER['CI'] ?? false)) {
    try {
        Dotenv::createImmutable(__DIR__.'/../', '.env.testing')->load();
    } catch (InvalidPathException $e) {
        echo 'You have not configured your local `.env.testing` file. Please run `cp .env.example .env.testing` and configure the variables as needed.';

        exit(1);
    }
}

$fallbackToken = 'fakepkxoLBIOgPE0PZWadR0Ge1zHBh31ATOzXN9bBboZ';
$fallbackBaseUrl = 'https://watchtower.test';

foreach ([
    'WATCHTOWER_TOKEN' => $fallbackToken,
    'WATCHTOWER_BASE_URL' => $fallbackBaseUrl,
] as $key => $fallback) {
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

    if (! is_string($value) || $value === '') {
        $_SERVER[$key] = $fallback;
        $_ENV[$key] = $fallback;
        putenv($key.'='.$fallback);
    }
}

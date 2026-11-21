<?php

return [
    'enabled' => env('WATCHTOWER_ENABLED', true),
    'token' => env('WATCHTOWER_TOKEN'),
    // The Watchtower platform. Set WATCHTOWER_BASE_URL only to point the agent somewhere else
    // (e.g. a self-hosted or staging platform). Read from config so the agent and
    // `watchtower:deploy` still work after `php artisan optimize` / `config:cache`.
    'base_url' => env('WATCHTOWER_BASE_URL') ?: 'https://api.watchtowers.cloud',
    'deployment' => env('WATCHTOWER_DEPLOY', env('LARAVEL_CLOUD_DEPLOY_UUID', env('FORGE_DEPLOY_COMMIT', env('VAPOR_COMMIT_HASH')))),
    'server' => env('WATCHTOWER_SERVER'),
    'capture_exception_source_code' => env('WATCHTOWER_CAPTURE_EXCEPTION_SOURCE_CODE', true),
    'capture_request_payload' => env('WATCHTOWER_CAPTURE_REQUEST_PAYLOAD', false),
    'redact_payload_fields' => explode(',', env('WATCHTOWER_REDACT_PAYLOAD_FIELDS', '_token,password,password_confirmation,current_password')),
    'redact_headers' => explode(',', env('WATCHTOWER_REDACT_HEADERS', 'Authorization,Cookie,Proxy-Authorization,X-CSRF-TOKEN,X-XSRF-TOKEN')),

    'sampling' => [
        'requests' => env('WATCHTOWER_REQUEST_SAMPLE_RATE', 1.0),
        'commands' => env('WATCHTOWER_COMMAND_SAMPLE_RATE', 1.0),
        'exceptions' => env('WATCHTOWER_EXCEPTION_SAMPLE_RATE', 1.0),
        'scheduled_tasks' => env('WATCHTOWER_SCHEDULED_TASK_SAMPLE_RATE', 1.0),
    ],

    'filtering' => [
        'ignore_cache_events' => env('WATCHTOWER_IGNORE_CACHE_EVENTS', false),
        'ignore_mail' => env('WATCHTOWER_IGNORE_MAIL', false),
        'ignore_notifications' => env('WATCHTOWER_IGNORE_NOTIFICATIONS', false),
        'ignore_outgoing_requests' => env('WATCHTOWER_IGNORE_OUTGOING_REQUESTS', false),
        'ignore_queries' => env('WATCHTOWER_IGNORE_QUERIES', false),
        'log_level' => env('WATCHTOWER_LOG_LEVEL', env('LOG_LEVEL', 'debug')),
    ],

    // Sampling and filters chosen in the Watchtower panel: the agent writes them to
    // bootstrap/cache/watchtower-settings.php and they override the values below. Turn off to
    // keep only this file's settings.
    'remote_settings' => env('WATCHTOWER_REMOTE_SETTINGS', true),

    // The agent process (`php artisan watchtower:agent`).
    'agent' => [
        'log_level' => env('WATCHTOWER_AGENT_LOG_LEVEL'),
        // Where undelivered payloads wait until Watchtower is reachable; an empty string turns it off.
        'spool_path' => env('WATCHTOWER_SPOOL_PATH'),
        'spool_max_mb' => env('WATCHTOWER_SPOOL_MAX_MB'),
        'spool_max_age_hours' => env('WATCHTOWER_SPOOL_MAX_AGE_HOURS'),
    ],

    'ingest' => [
        'uri' => env('WATCHTOWER_INGEST_URI', '127.0.0.1:2407'),
        'timeout' => env('WATCHTOWER_INGEST_TIMEOUT', 0.5),
        'connection_timeout' => env('WATCHTOWER_INGEST_CONNECTION_TIMEOUT', 0.5),
        'event_buffer' => env('WATCHTOWER_INGEST_EVENT_BUFFER', 500),
    ],
];

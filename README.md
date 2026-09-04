# watchtowerapm/agent

A modern, open-source Laravel APM agent inspired by Laravel Nightwatch and the best of modern observability tooling.

This project is derived from [`laravel/nightwatch`](https://github.com/laravel/nightwatch) v1.30.0, which is licensed under the MIT License.

Watchtower Agent instruments Laravel applications and sends telemetry only to a Watchtower instance. It does not connect to Laravel’s hosted Nightwatch service.

See [NOTICE.md](NOTICE.md) for the fork boundary (MIT-derived sensors vs Watchtower transport).

## Install

```bash
composer require watchtowerapm/agent
```

```env
WATCHTOWER_ENABLED=true
WATCHTOWER_TOKEN=
WATCHTOWER_BASE_URL=https://your-watchtower-api.example
WATCHTOWER_INGEST_URI=127.0.0.1:2407
```

`WATCHTOWER_BASE_URL` is required and identifies the Watchtower platform instance. No Laravel Nightwatch hosted endpoint is configured or used by default.

Optional log capture:

```env
LOG_CHANNEL=watchtower
# or
LOG_STACK=watchtower,single
LOG_CHANNEL=stack
```

Run the Watchtower sidecar using the Docker image provided by this repository, or run:

```bash
php artisan watchtower:agent
```

## Upstream

Watchtower Agent contains code derived from Laravel Nightwatch:

https://github.com/laravel/nightwatch

Upstream version used as the initial basis:

`laravel/nightwatch` v1.30.0

Subsequent Watchtower-specific modifications include transport protocol, configuration, destination, authentication against a Watchtower instance, and branding.

## License

Watchtower Agent is distributed under the MIT License.

Portions derived from Laravel Nightwatch retain the original copyright and MIT license notice:

Copyright (c) Taylor Otwell

See [LICENSE.md](LICENSE.md) for the complete license text.

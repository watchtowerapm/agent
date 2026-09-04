# watchtowerapm/agent

Watchtower Laravel agent **1.0.0** — open-source APM instrumentation for Laravel that sends telemetry only to **your** Watchtower instance.

This project is derived from [`laravel/nightwatch`](https://github.com/laravel/nightwatch) v1.30.0 (MIT). It does **not** connect to Laravel’s hosted Nightwatch service. Composer authors are Watchtower; Nightwatch attribution lives in [LICENSE.md](LICENSE.md) and [NOTICE.md](NOTICE.md).

| | |
| --- | --- |
| Package version | **1.0.0** (`version.txt`) |
| Git branch | `1.x` |
| Wire protocol | `watchtower` / `protocol_version` **1** |
| PHP | 8.2–8.5 |
| Laravel | 10–13 |

**Documentation**

- [Architecture](docs/architecture.md) — SDK, sidecar, platform
- [Protocol](docs/protocol.md) — frames, HELLO/WELCOME, session, sequence, batch limits
- [Fork and 1.0.0 scope](docs/fork.md) — what we kept, what we own, what is deferred
- [Development](docs/development.md) — test, lint, CI, phar, Docker
- [Changelog](CHANGELOG.md)

---

## What it does

1. Hooks Laravel (HTTP, queue, schedule, mail, cache, queries, exceptions, …).
2. Sensors turn those events into records (existing `t` + field shapes).
3. The package opens TCP to a local **sidecar** (`WATCHTOWER_INGEST_URI`, default `127.0.0.1:2407`) and speaks the Watchtower session protocol (not Nightwatch `v1:tokenHash:payload`).
4. The sidecar authenticates to `{WATCHTOWER_BASE_URL}/api/agent-auth` and forwards batches to the ingest URL in that response.

```
App  --Watchtower frames-->  sidecar  --HTTPS-->  Watchtower platform
```

---

## Install

```bash
composer require watchtowerapm/agent
```

Publish config if you want a file in the app:

```bash
php artisan vendor:publish --tag=watchtower-config
```

### Environment

```env
WATCHTOWER_ENABLED=true
WATCHTOWER_TOKEN=
WATCHTOWER_BASE_URL=https://your-watchtower-api.example
WATCHTOWER_INGEST_URI=127.0.0.1:2407
```

| Variable | Required | Purpose |
| --- | --- | --- |
| `WATCHTOWER_TOKEN` | Yes for sidecar + HELLO hash | Platform bearer token |
| `WATCHTOWER_BASE_URL` | Yes for sidecar process | Platform origin (no Nightwatch default) |
| `WATCHTOWER_INGEST_URI` | No | Where the app sends frames / sidecar listens |
| `WATCHTOWER_ENABLED` | No | Default `true` |
| `WATCHTOWER_DEPLOY` | For `watchtower:deploy` | Deploy identifier |

Sampling, filtering, and redaction: `config/watchtower.php` and [configure-watchtower](resources/boost/skills/configure-watchtower/SKILL.md).

Optional logs:

```env
LOG_CHANNEL=watchtower
# or
LOG_STACK=watchtower,single
LOG_CHANNEL=stack
```

### Run the sidecar

The Laravel process is not the sidecar. Run one sidecar per host (or container) that should accept local frames:

```bash
php artisan watchtower:agent
```

If `agent/build/agent.phar` exists, that binary is used; otherwise `agent/src/agent.php`. After protocol changes, use a rebuilt phar (CI commits it on `1.x`).

Docker image from this repo:

```bash
docker build -f agent/Dockerfile -t watchtower-agent .
docker run --rm \
  -e WATCHTOWER_TOKEN \
  -e WATCHTOWER_BASE_URL \
  -p 2407:2407 \
  watchtower-agent
```

Health: `php artisan watchtower:status` or `php agent/watchtower-status` (needs `WATCHTOWER_TOKEN`).

Deploy notification (HTTPS to the platform, not the local protocol):

```bash
php artisan watchtower:deploy
```

---

## Protocol (short)

Framing: `{byte_length}\\n{json}`.

```
HELLO (protocol=watchtower, protocol_version=1, token_hash)
  → WELCOME (session_id, max_batch=500)
  → telemetry_batch | ping  (session_id, sequence=1)
  → ACK
```

The sidecar rejects mismatched session, sequence ≠ 1, `batch_version` ≠ 1, and batches larger than 500. Socket auth is a short `token_hash` of the env token — **not HMAC**. Bind the listen address to localhost. Details: [docs/protocol.md](docs/protocol.md).

---

## Repository layout

| Path | Role |
| --- | --- |
| `src/` | Laravel package |
| `agent/` | Sidecar, Docker, phar build |
| `tests/` | Package tests |
| `agent/tests/` | Sidecar tests |
| `docs/` | Protocol and contributor docs |

---

## Testing and CI

You **do not** need a real Watchtower in GitHub (or locally) to run the suite.

PHPUnit uses fixture `WATCHTOWER_TOKEN` / `https://watchtower.test`. Tests that would call a live `/api/agent-auth` skip when the URL is that fixture.

```bash
composer install
composer test
cd agent && composer install && composer test
```

Lint: `vendor/bin/pint --test` and `vendor/bin/phpstan` at the repo root and under `agent/`.

GitHub Actions (`.github/workflows/pull_request.yml`): Pint, PHPStan, phar build, package matrix (PHP × Laravel × prefer-lowest), agent tests, Docker health check. Secrets are optional; they default to the same fixtures.

Full contributor workflow: [docs/development.md](docs/development.md).

---

## Versioning

- **1.0.0** is the first Watchtower package release (this is not Nightwatch 2.x and not “protocol 2”).
- Bump `version.txt` and `composer.json` together.
- `protocol_version` / `batch_version` change only when the wire contract changes (see protocol docs).

---

## License

MIT. Portions derived from Laravel Nightwatch:

Copyright (c) Taylor Otwell

See [LICENSE.md](LICENSE.md) and [NOTICE.md](NOTICE.md).

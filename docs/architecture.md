# Architecture

This repository is the **Laravel agent**: application instrumentation plus a local **sidecar** that talks to a Watchtower platform. It does not embed the Watchtower UI or API.

```
Laravel app (PHP-FPM / Octane / CLI / queue)
        │  TCP 127.0.0.1:2407
        │  Watchtower frames (HELLO → batch/PING)
        ▼
Sidecar (`php artisan watchtower:agent` or Docker / agent.phar)
        │  HTTPS WATCHTOWER_BASE_URL
        │  POST /api/agent-auth  then ingest URL from the JWT-style response
        ▼
Watchtower platform (your deployment — not Laravel Nightwatch Cloud)
```

## Packages in this repo

| Path | Role |
| --- | --- |
| `src/` | Laravel package (`Watchtower\Laravel\`): hooks, sensors, records, `Ingest` client |
| `config/watchtower.php` | Published config, `WATCHTOWER_*` env |
| `agent/src/` | Sidecar (`Watchtower\LaravelAgent\`): TCP server, cloud ingest HTTP, event loop |
| `agent/build/agent.phar` | Boxed sidecar used by `watchtower:agent` when present |
| `agent/watchtower-status` | Health check: HELLO + PING to the local listen address |
| `workbench/` | Orchestra Testbench app for package tests |

The Composer package name is `watchtowerapm/agent`. The sidecar’s internal `composer.json` name is `watchtower/laravel-agent` and is not published separately.

## Laravel package (`src/`)

1. **Service provider** (`WatchtowerServiceProvider`) merges config, registers the logger channel, Artisan commands, and wires `Core`.
2. **Hooks** (`src/Hooks/`) listen to Laravel events (requests, queries, jobs, mail, …). Failures inside hooks are reported as handled so monitoring cannot take down the app.
3. **Sensors** (`src/Sensors/`) turn events into **records** (`src/Records/`) and JSON payload factories (`t` + fields).
4. **`Core`** owns sampling, filtering, redaction, execution stage, and the ingest buffer.
5. **`Ingest`** (`src/Ingest.php`) opens a TCP connection to `WATCHTOWER_INGEST_URI`, speaks the Watchtower protocol (`src/Transport/Frame.php`, `src/Transport/Protocol.php`), and writes `telemetry_batch` or `ping`.

Nightwatch-compatible job payload keys (for trace correlation) may still appear in queue payloads. They are transport compatibility, not a Nightwatch Cloud dependency.

## Sidecar (`agent/`)

`agent/src/agent.php` is the process entrypoint (also compiled into the phar).

1. Requires `WATCHTOWER_TOKEN`. `WATCHTOWER_BASE_URL` defaults to `https://api.watchtowers.cloud` and only needs setting for another platform.
2. Listens on `WATCHTOWER_INGEST_URI` (default `127.0.0.1:2407`; the Docker image uses `0.0.0.0:2407` so other containers can reach it, so keep that port on a private network).
3. **`Server`** checks the signed HELLO, issues `session_id` and a signed WELCOME, then accepts one signed PING or `telemetry_batch`, ACKs, and closes the socket (one-shot session).
4. **`IngestDetailsRepository`** POSTs `{}` to `{WATCHTOWER_BASE_URL}/api/agent-auth` with `Authorization: Bearer {token}` and expects JSON: `token`, `expires_in`, `refresh_in`, `ingest_url`.
5. **`Ingest` (sidecar)** journals each accepted batch to disk before the ACK (`Journal`), buffers it, and gzip-POSTs it to `ingest_url`. Failed uploads go to the `Spool` and are retried; on start, batches a crashed run had acknowledged are recovered from the journal into the spool.

The sidecar sends a `watchtower-server` HTTP header (the host name) on auth and ingest requests. If ingest answers `401`, the sidecar drops its cached ingest token and authenticates again before the next batch.

## Commands

| Command | Purpose |
| --- | --- |
| `php artisan watchtower:agent` | Run the sidecar (phar if `agent/build/agent.phar` exists, else `agent/src/agent.php`) |
| `php artisan watchtower:status` | PING the local sidecar |
| `php artisan watchtower:deploy` | HTTP deploy notification to the platform (`/api/deployments`) |
| `php watchtower-status` (in `agent/`) | Same PING as Docker `HEALTHCHECK` |

## Configuration

Published as `config/watchtower.php`. Important env vars:

| Variable | Role |
| --- | --- |
| `WATCHTOWER_ENABLED` | Master switch |
| `WATCHTOWER_TOKEN` | Platform token; the session key that signs every local message is derived from it |
| `WATCHTOWER_BASE_URL` | Platform origin for sidecar auth (default `https://api.watchtowers.cloud`) |
| `WATCHTOWER_INGEST_URI` | Sidecar listen / client connect address |
| `WATCHTOWER_DEPLOY` | Deploy id for `watchtower:deploy` |
| Sampling / filter / redact | See `resources/boost/skills/configure-watchtower/` |

CI and local PHPUnit use fixture values `https://watchtower.test` and a fake token so tests do not need a live platform. Those values are **not** a hosted Watchtower. Live sidecar authentication tests are skipped when the base URL contains `watchtower.test`.

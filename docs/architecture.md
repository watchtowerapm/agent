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

1. Requires `WATCHTOWER_TOKEN` and `WATCHTOWER_BASE_URL`.
2. Listens on `WATCHTOWER_INGEST_URI` (Docker default `0.0.0.0:2407`).
3. **`Server`** accepts one HELLO, issues `session_id`, then one PING or one `telemetry_batch`, ACKs, and closes the socket (one-shot session).
4. **`IngestDetailsRepository`** POSTs `{}` to `{WATCHTOWER_BASE_URL}/api/agent-auth` with `Authorization: Bearer {token}` and expects JSON: `token`, `expires_in`, `refresh_in`, `ingest_url`.
5. **`Ingest` (sidecar)** gzip-POSTs accepted record JSON to `ingest_url`.

The sidecar still sends a `nightwatch-server` HTTP header on ingest. Watchtower ingest accepts that key so platform routing can stay stable while the local wire protocol is Watchtower-owned.

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
| `WATCHTOWER_TOKEN` | Platform token; hashed for HELLO `token_hash` |
| `WATCHTOWER_BASE_URL` | Platform origin (required for sidecar auth) |
| `WATCHTOWER_INGEST_URI` | Sidecar listen / client connect address |
| `WATCHTOWER_DEPLOY` | Deploy id for `watchtower:deploy` |
| Sampling / filter / redact | See `resources/boost/skills/configure-watchtower/` |

CI and local PHPUnit use fixture values `https://watchtower.test` and a fake token so tests do not need a live platform. Those values are **not** a hosted Watchtower. Live sidecar authentication tests are skipped when the base URL contains `watchtower.test`.

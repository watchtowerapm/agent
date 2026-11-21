# Release Notes

## 1.2.1 - 2026-11-21

- Agent: `psr/http-message` 2.0 (from 1.1), still resolved for PHP 8.2. Internal to the agent binary; no change in behaviour.
- README and NOTICE link the Watchtower Terms of Service that apply when the agent is used with the Watchtower service. The code stays MIT-licensed.

## 1.2.0 - 2026-11-21

- Sampling rates and event filters can be set per environment in the Watchtower panel, without editing `.env` or redeploying. The agent receives them when it authenticates and writes them to `bootstrap/cache/watchtower-settings.php` (only known keys with valid values, only when they change; removed when the panel has none). The package merges them over its config at boot: a plain `return [...]` file, so with opcache it costs next to nothing per request. Settings left empty in the panel keep the app's own values.
- `WATCHTOWER_REMOTE_SETTINGS=false` (config `remote_settings`) ignores the panel's settings.
- Agents running in a separate container from the app can't write the file; the app's own settings apply there. Point `WATCHTOWER_SETTINGS_PATH` at a shared path to use it anyway.

## 1.1.2 - 2026-11-21

- The agent and `watchtower:deploy` now work after `php artisan optimize` / `config:cache`. With a cached config Laravel no longer loads `.env`, so `WATCHTOWER_BASE_URL` (and the agent's log-level and spool settings) never reached the agent, which stopped with "Please configure the [WATCHTOWER_BASE_URL] environment variable". `watchtower:agent` now passes these settings from the config; variables set in the shell, Supervisor or Docker still take priority.
- `WATCHTOWER_BASE_URL` is optional: it defaults to `https://api.watchtowers.cloud`. Set it only to point the agent at another Watchtower platform (staging or self-hosted).
- New config keys: `base_url`, and `agent.log_level`, `agent.spool_path`, `agent.spool_max_mb`, `agent.spool_max_age_hours` (same environment variables as before). Apps that published `config/watchtower.php` keep working; add the keys to use them with a cached config.

## 1.1.1 - 2026-11-19

- Sidecar: when ingest cannot be reached (DNS, connection, TLS or timeout), it authenticates again on the next attempt, at most once a minute, instead of reusing the cached ingest URL and token until the hourly refresh. A corrected ingest URL now reaches running agents within about a minute.
- Sidecar: `react/http` 1.12.0. Its dependencies are now resolved for PHP 8.2 (`config.platform`), so updates can no longer pull in packages that need a newer PHP than the agent supports.

## 1.1.0 - 2026-11-19

Brings in the fixes from upstream `laravel/nightwatch` v1.30.1 – v1.30.6 that apply to Watchtower, and hardens the agent ↔ sidecar protocol. `protocol_version` stays `1`; every change is backward compatible with 1.0.0 clients.

**Security**

- Sidecar checks `token_hash` (constant-time) before the protocol version, so an unauthenticated local client can no longer make the sidecar shut down by sending a bad version (upstream #432).
- Default redaction now also covers `current_password` and the `X-CSRF-TOKEN` header (upstream #431).
- Sidecar Docker image upgrades Alpine packages at build time (CVE-2026-111497 in brotli-libs, upstream #442).

**Reliability**

- The sidecar keeps payloads it could not deliver (Watchtower unreachable or busy) in an on-disk spool and resends them, oldest first, once ingest accepts data again. Bounded by `WATCHTOWER_SPOOL_MAX_MB` (default 100) and `WATCHTOWER_SPOOL_MAX_AGE_HOURS` (default 24); the oldest payloads are dropped first. Set `WATCHTOWER_SPOOL_PATH` to choose the directory, or to an empty string to turn it off.
- Transmission failures in `Ingest::writeNow()` and `digest()` are always handled as unrecoverable and never reach the application (upstream #443).
- Buffers larger than the sidecar's `max_batch` are sent as several batches instead of being dropped.
- An expired ingest token is refreshed before ingesting, and refresh timers no longer pile up (upstream #428). A `401` from ingest now triggers re-authentication on the next batch instead of failing until the hourly refresh.
- The server hostname is resolved at runtime, so `config:cache` on a build machine no longer bakes in the wrong host (upstream #437). Set `WATCHTOWER_SERVER` to override.
- Fixed a `TypeError` when the sidecar's TCP server itself reported an error.

**Protocol hardening** (see `docs/protocol.md`)

- Frames must have a numeric length prefix and a body of at most 16 MiB that is a JSON object; anything else is rejected immediately with `invalid_frame` instead of hanging until the connection drops.
- `records` must be a JSON list (`invalid_records`).
- Connections that do not finish their exchange within 10 seconds are closed (`idle_timeout`).
- Frames sent after the sidecar has replied are ignored.
- The SDK reports the sidecar's rejection code, e.g. `The Watchtower agent rejected the connection [token_mismatch]`.
- The sidecar sends a `watchtower-server` header to ingest instead of `nightwatch-server`.

## 1.0.0 - 2026-09-05

First Watchtower Laravel agent release. Package version is **1.0.0** on the `1.x` branch.

Derived from [`laravel/nightwatch`](https://github.com/laravel/nightwatch) v1.30.0 (MIT). Sensors, records, hooks, sampling, and redaction stay on that lineage. Composer authors are Watchtower; Taylor Otwell / Nightwatch remain in `LICENSE.md` and `NOTICE.md`.

Watchtower owns the agent ↔ sidecar wire protocol: length-prefixed JSON frames (`{byte_length}\\n{json}`) with HELLO → WELCOME → `telemetry_batch` or PING → ACK. The sidecar enforces `session_id`, `sequence` (first command is `1`), `batch_version` (`1`), and `max_batch` (`500`). Socket auth is still a shared `token_hash` of `WATCHTOWER_TOKEN`. HMAC/challenge auth is not in this release.

See [docs/](docs/README.md) for protocol, architecture, and development.

Upstream Nightwatch git history is not in this repository. This changelog tracks Watchtower releases only.

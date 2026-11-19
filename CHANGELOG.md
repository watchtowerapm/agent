# Release Notes

## Unreleased

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

# Release Notes

## 1.0.0 - 2026-09-05

First Watchtower Laravel agent release. Package version is **1.0.0** on the `1.x` branch.

Derived from [`laravel/nightwatch`](https://github.com/laravel/nightwatch) v1.30.0 (MIT). Sensors, records, hooks, sampling, and redaction stay on that lineage. Composer authors are Watchtower; Taylor Otwell / Nightwatch remain in `LICENSE.md` and `NOTICE.md`.

Watchtower owns the agent ↔ sidecar wire protocol: length-prefixed JSON frames (`{byte_length}\\n{json}`) with HELLO → WELCOME → `telemetry_batch` or PING → ACK. The sidecar enforces `session_id`, `sequence` (first command is `1`), `batch_version` (`1`), and `max_batch` (`500`). Socket auth is still a shared `token_hash` of `WATCHTOWER_TOKEN`. HMAC/challenge auth is not in this release.

See [docs/](docs/README.md) for protocol, architecture, and development.

Upstream Nightwatch git history is not in this repository. This changelog tracks Watchtower releases only.

# NOTICE

Watchtower Agent contains code derived from Laravel Nightwatch:

https://github.com/laravel/nightwatch

**Upstream version:** `laravel/nightwatch` v1.30.0 (MIT License).  
Copyright (c) Taylor Otwell — see LICENSE.md.

## What was forked

Laravel instrumentation remains MIT-derived where it is useful:

- Sensors
- Records
- Hooks
- Sampling, redaction, and execution-state helpers

## What Watchtower owns

From 2.0.0, the agent ↔ sidecar session is a Watchtower protocol, not Nightwatch’s length-prefixed `v1:tokenHash:payload` / `2:OK` ingest framing.

| Layer | Versioning |
| --- | --- |
| Protocol | `protocol` = `watchtower`, `protocol_version` = 1 (HELLO / WELCOME) |
| Batch | `batch_version` = 1 (`telemetry_batch`) |
| Events | Existing record shapes (`t`, fields) until a Watchtower event model lands |

Handshake: HELLO → WELCOME (`session_id`, `max_batch`) → TELEMETRY_BATCH or PING → ACK.

Auth on the socket is still a shared `token_hash` of the environment token. HMAC/session secrets can replace that without changing sensors.

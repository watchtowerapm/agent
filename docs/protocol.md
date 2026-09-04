# Watchtower agent ↔ sidecar protocol

**Package:** 1.0.0  
**Protocol name:** `watchtower`  
**Protocol version:** `1`  
**Batch version:** `1`  
**Max batch:** `500` records  

Canonical constants: `src/Transport/Protocol.php` (SDK) and `agent/src/Protocol.php` (sidecar). Keep them identical.

This document describes the **local TCP session** between the Laravel package and the sidecar. Cloud ingest (`/api/agent-auth` + ingest URL) is a separate HTTPS API.

## Design goals

- Own the session independently of Nightwatch’s `length:v1:tokenHash:payload` / `2:OK` framing.
- Keep Sensors/Records JSON (`t`, fields) unchanged so the platform can ingest the same event bodies.
- Enforce session, sequence, batch version, and batch size on the sidecar.
- Leave HMAC/challenge as a later WELCOME upgrade without rewriting instrumentation.

## Framing

Every message is a **length-prefixed JSON object**:

```
{byte_length}\n{json}
```

- `byte_length` is the UTF-8 byte length of the JSON body (PHP `strlen` of the encoded string), decimal, no padding.
- JSON flags: `JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
- Implementation: `Watchtower\Laravel\Transport\Frame` and `Watchtower\LaravelAgent\Frame` (same encode/decode).

Example (illustrative):

```
42
{"type":"ping","sequence":1}
```

(Actual length must match the JSON bytes.)

Incomplete buffers are held in `FrameBuffer` until a full frame arrives. Truncated connections without a clean close are reported as connection errors.

## Connection model

Each TCP connection is **one-shot**:

1. Client connects to `WATCHTOWER_INGEST_URI` (default `127.0.0.1:2407`).
2. Client sends **HELLO**.
3. Sidecar replies **WELCOME** (or **ERROR** and closes).
4. Client sends **one** `telemetry_batch` **or** **one** `ping`.
5. Sidecar replies **ACK** (or **ERROR**) and **closes**.

`sequence` is therefore always `1` for a successful command on that socket. A second command on the same connection is not part of v1. Reconnect and HELLO again for the next flush or ping.

The Laravel `Ingest` client always starts `sequence` at `1` after WELCOME (`src/Ingest.php`).

## Handshake: HELLO

Client → sidecar.

| Field | Type | Required | Meaning |
| --- | --- | --- | --- |
| `type` | string | yes | `"hello"` |
| `protocol` | string | yes | `"watchtower"` |
| `protocol_version` | int | yes | `1` |
| `token_hash` | string | yes | First 7 chars of `xxh128(WATCHTOWER_TOKEN)` |
| `agent_version` | string | no | Package version from `version.txt` (e.g. `1.0.0`) |
| `sdk` | string | no | `"laravel"` |
| `php_version` | string | no | `PHP_VERSION` |

Sidecar rejects before WELCOME when:

| Condition | Error `code` | Extra |
| --- | --- | --- |
| First frame is not HELLO | `expected_hello` | |
| `protocol` ≠ `watchtower` or `protocol_version` ≠ `1` | `unsupported_protocol` | Sidecar listen loop stops (`onInvalidPayloadVersion`) |
| `token_hash` ≠ sidecar hash | `token_mismatch` | `onInvalidTokenHash` callback |

`token_hash` is **not** HMAC. Anyone who can reach the listen port and present the same short hash is accepted. Bind ingest to localhost in production.

## Handshake: WELCOME

Sidecar → client.

| Field | Type | Meaning |
| --- | --- | --- |
| `type` | string | `"welcome"` |
| `accepted` | bool | `true` |
| `protocol_version` | int | `1` |
| `session_id` | string | `sess_` + 16 hex chars (`random_bytes(8)`) |
| `max_batch` | int | `500` |

The client must copy `session_id` onto the following command. If WELCOME `max_batch` is missing or invalid, the SDK falls back to `Protocol::MAX_BATCH`.

## Commands after WELCOME

Only `ping` and `telemetry_batch` are allowed. Anything else → `unexpected_type`.

### Session validation

`session_id` on the command must equal the WELCOME id. Otherwise `session_mismatch`.

### Strict sequence

`sequence` must be the integer `1` (first command on this connection). Otherwise `sequence_mismatch`.

There is no incrementing counter across reconnects. “Strict” means: do not skip, do not start at `0` or `2`, do not reuse a previous session’s sequence on a new HELLO without sending `1` again.

### PING

Client → sidecar. Used by `watchtower:status` and `watchtower-status`.

| Field | Type | Required |
| --- | --- | --- |
| `type` | string | `"ping"` |
| `protocol_version` | int | `1` |
| `session_id` | string | from WELCOME |
| `sequence` | int | `1` |

Sidecar ACKs with `accepted: 0` (no records) and closes. PING does not require `batch_version`.

### TELEMETRY_BATCH

Client → sidecar.

| Field | Type | Required |
| --- | --- | --- |
| `type` | string | `"telemetry_batch"` |
| `protocol` | string | `"watchtower"` |
| `protocol_version` | int | `1` |
| `batch_version` | int | `1` |
| `session_id` | string | from WELCOME |
| `sequence` | int | `1` |
| `records` | array | list of event objects |

**Batch version:** `batch_version` must be `1` or the sidecar returns `unsupported_batch_version`. Bump this when the **batch envelope** changes, not when a single record field is added.

**Max batch:** `count(records)` must be `≤ 500`. Otherwise `batch_too_large`. Empty arrays are allowed by the count check (`0 ≤ 500`); the SDK usually does not send empty flushes.

The SDK also refuses to send if `count(records) > max_batch` from WELCOME (`RuntimeException`).

Record objects are the existing Nightwatch-shaped payloads (`t`, `timestamp`, …). The sidecar JSON-encodes the `records` array and forwards it to cloud ingest. It does not validate individual DTOs in 1.0.0.

## ACK

Sidecar → client, then close.

| Field | Type | Meaning |
| --- | --- | --- |
| `type` | string | `"ack"` |
| `sequence` | int | Echo of the command sequence |
| `accepted` | int | Record count for a batch; `0` for PING |
| `rejected` | int | `0` on success |

The SDK requires `type=ack`, matching `sequence`, and `rejected === 0`.

## ERROR

Sidecar → client, then close.

| Field | Type | Meaning |
| --- | --- | --- |
| `type` | string | `"error"` |
| `accepted` | bool | `false` |
| `code` | string | Machine-readable reason |

### Error codes (sidecar)

| `code` | When |
| --- | --- |
| `expected_hello` | Non-HELLO before a session exists |
| `unsupported_protocol` | Wrong `protocol` / `protocol_version` |
| `token_mismatch` | `token_hash` does not match |
| `unexpected_type` | After WELCOME, not ping or telemetry_batch |
| `session_mismatch` | `session_id` ≠ issued session |
| `sequence_mismatch` | `sequence` ≠ expected (`1`) |
| `unsupported_batch_version` | `batch_version` ≠ `1` |
| `batch_too_large` | More than 500 records, or `records` not an array |

## Nightwatch wire (what we replaced)

Nightwatch used a colon-delimited payload, roughly `length:v1:{tokenHash}:{payload}`, with replies like `2:OK`. Watchtower 1.0.0 **does not** speak that format. Mixing a Nightwatch agent with a Watchtower sidecar (or the reverse) will fail handshake.

## Cloud ingest (not this TCP protocol)

After the sidecar accepts a batch it still authenticates to **your** Watchtower origin:

- `POST {WATCHTOWER_BASE_URL}/api/agent-auth`
- Bearer token = `WATCHTOWER_TOKEN`
- Response must include `token`, `expires_in` (int), `refresh_in` (int), `ingest_url` (string)

Retries use a backoff schedule in `IngestDetailsRepository`. GitHub Actions does **not** call a real platform: tests fake HTTP or skip live auth when `WATCHTOWER_BASE_URL` contains `watchtower.test`.

## HMAC / challenge (not in 1.0.0)

Intended shape when added, without changing Sensors:

1. WELCOME includes a per-session secret or server nonce.
2. Client HMACs `sequence || body` (or the full JSON) with that secret.
3. Sidecar rejects missing/invalid MAC with a new error code.

Until then, treat the listen port as trusted-localhost only.

## Tests that lock the protocol

| Area | Tests |
| --- | --- |
| Sidecar rejects | `agent/tests/Feature/ServerTest.php` |
| SDK frames | `tests/Unit/IngestTest.php` |
| Frame codec | `agent/tests/Unit/FrameBufferTest.php` |
| Status PING | `tests/Feature/Console/StatusCommandTest.php`, `agent/tests/Integration/HealthCheckTest.php` |

When changing the protocol, update **both** `Protocol.php` copies, fakes (`tests/SidecarReply.php`, `agent/tests/TcpServerFake.php`, `tests/FakeIngest.php`), this document, and `NOTICE.md`.

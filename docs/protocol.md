# Watchtower agent ↔ sidecar protocol

**Protocol name:** `watchtower`  
**Protocol version:** `2` (signed sessions; `1` was the unsigned token-hash handshake)  
**Batch version:** `2` (records are the frame's last member, signed as sent)  
**Max batch:** `500` records  

Canonical constants: `src/Transport/Protocol.php` (SDK) and `agent/src/Protocol.php` (sidecar). Signatures: `src/Transport/Mac.php` and `agent/src/Mac.php`. Keep each pair identical (a test checks `Mac`).

This document describes the **local TCP session** between the Laravel package and the sidecar. Cloud ingest (`/api/agent-auth` + ingest URL) is a separate HTTPS API.

## Design goals

- Own the session independently of Nightwatch’s `length:v1:tokenHash:payload` / `2:OK` framing.
- Keep Sensors/Records JSON (`t`, fields) unchanged so the platform can ingest the same event bodies.
- Enforce session, sequence, batch version, and batch size on the sidecar.
- Authenticate both ends and every command with HMAC, without the token ever crossing the socket, and make recorded exchanges useless to replay.

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

Validation (`Frame::tryDecodeOne`, same in both copies):

- The length prefix must be 1–9 decimal digits. Anything else (letters, a sign, a missing newline after 9 bytes) is invalid.
- The body must be 2 bytes to **16 MiB** (`Protocol::MAX_FRAME_BYTES`) and decode to a JSON **object**.
- "Not enough bytes yet" and "can never be valid" are different outcomes. Incomplete frames wait in `FrameBuffer`; invalid ones are rejected at once with `invalid_frame`, so neither side waits for a timeout on garbage.

Truncated connections without a clean close are reported as connection errors.

## Connection model

Each TCP connection is **one-shot**:

1. Client connects to `WATCHTOWER_INGEST_URI` (default `127.0.0.1:2407`).
2. Client sends **HELLO**.
3. Sidecar replies **WELCOME** (or **ERROR** and closes).
4. Client sends **one** `telemetry_batch` **or** **one** `ping`.
5. Sidecar replies **ACK** (or **ERROR**) and **closes**.

`sequence` is therefore always `1` for a successful command on that socket. A second command on the same connection is not part of v1. Reconnect and HELLO again for the next flush or ping. Anything the client sends after the sidecar's ACK or ERROR is ignored.

**Idle timeout:** the sidecar closes a connection that has not finished its exchange within **10 seconds** (`Protocol::IDLE_TIMEOUT_SECONDS`) with `idle_timeout`, so a stuck client cannot hold sockets open. Local SDK writes complete in milliseconds.

The Laravel `Ingest` client always starts `sequence` at `1` after WELCOME (`src/Ingest.php`).

## Signatures

Both sides derive one key from the full token:

```
key = HKDF-SHA256(ikm = "watchtower:" . WATCHTOWER_TOKEN, length = 32, info = "watchtower-sidecar-session")
```

Every `mac` is the lowercase hex `HMAC-SHA256(key, parts joined with "\n")`:

| Message | Signed parts |
| --- | --- |
| HELLO | `hello`, `protocol_version`, client `nonce` |
| WELCOME | `welcome`, client nonce, `session_id`, sidecar `nonce` |
| PING / TELEMETRY_BATCH | `type`, `session_id`, `sequence`, client nonce, sidecar nonce, body length, `xxh128(body)` (body = the raw `records` JSON, or empty for PING) |

Nonces are 16 random bytes, hex-encoded, fresh for each connection. Comparisons use `hash_equals`.

What this gives:

- **No shared secret on the wire.** Only MACs travel; the token can't be read off the socket.
- **Both ends authenticated.** The sidecar refuses a HELLO it can't verify. The SDK refuses a WELCOME it can't verify and sends **no records** to a listener that doesn't hold the token.
- **No replay.** A command is bound to this connection's session id and both nonces, so a recorded batch or ping fails on any other connection.
- **Records bound to the session.** The signature covers the length and an xxh128 digest of the exact records bytes, which the sidecar forwards unchanged.

What it doesn't give: xxh128 isn't collision-resistant, so the records aren't protected against someone who can already rewrite this connection's traffic (root on the host, or raw access to a shared container network). Signing the full body with HMAC-SHA256 instead costs more than encoding the batch (measured: 1.1 ms vs 0.37 ms for 500 records), on every request. Keep the port on localhost or a private network.

## Handshake: HELLO

Client → sidecar.

| Field | Type | Required | Meaning |
| --- | --- | --- | --- |
| `type` | string | yes | `"hello"` |
| `protocol` | string | yes | `"watchtower"` |
| `protocol_version` | int | yes | `2` |
| `nonce` | string | yes | Client nonce (32 hex chars) |
| `mac` | string | yes | See Signatures |
| `token_hash` | string | no | First 7 chars of `xxh128(WATCHTOWER_TOKEN)`. Not used for authentication. Sent only so a sidecar still on protocol 1 recognises an upgraded app and restarts (see Upgrades). |
| `agent_version` | string | no | Package version from `version.txt` (e.g. `1.0.0`) |
| `sdk` | string | no | `"laravel"` |
| `php_version` | string | no | `PHP_VERSION` |

Sidecar checks, **in this order**, and rejects before WELCOME when:

| Condition | Error `code` | Extra |
| --- | --- | --- |
| Bytes are not a valid frame | `invalid_frame` | Logged as a connection error |
| First frame is not HELLO | `expected_hello` | |
| No `mac`, but `token_hash` matches and `protocol_version` < 2 | `unsupported_protocol` | An app still on protocol 1. Logged; the sidecar keeps running (it is the newer side) |
| `mac` missing, or not valid for this `nonce` and `protocol_version` | `token_mismatch` | `onInvalidTokenHash` callback |
| `protocol` ≠ `watchtower` or `protocol_version` ≠ `2` | `unsupported_protocol` | Sidecar stops (`onInvalidPayloadVersion`) |

The signature is checked **before** the protocol so only a client that holds the token can make the sidecar stop. A newer version from an authenticated client means the app was upgraded and the sidecar wasn't restarted yet; the sidecar flushes and exits so the process manager restarts it on the new version.

## Handshake: WELCOME

Sidecar → client.

| Field | Type | Meaning |
| --- | --- | --- |
| `type` | string | `"welcome"` |
| `accepted` | bool | `true` |
| `protocol_version` | int | `2` |
| `session_id` | string | `sess_` + 16 hex chars (`random_bytes(8)`) |
| `max_batch` | int | `500` |
| `nonce` | string | Sidecar nonce (32 hex chars) |
| `mac` | string | See Signatures |

The client verifies `mac` before sending anything else; if it doesn't match, it closes the connection and reports `The Watchtower agent at [...] could not prove it uses the same WATCHTOWER_TOKEN as this application`. It then copies `session_id` onto the following command. If WELCOME `max_batch` is missing or invalid, the SDK falls back to `Protocol::MAX_BATCH`.

## Commands after WELCOME

Only `ping` and `telemetry_batch` are allowed. Anything else → `unexpected_type`.

### Session validation

`session_id` on the command must equal the WELCOME id. Otherwise `session_mismatch`.

### Strict sequence

`sequence` must be the integer `1` (first command on this connection). Otherwise `sequence_mismatch`.

There is no incrementing counter across reconnects. “Strict” means: do not skip, do not start at `0` or `2`, do not reuse a previous session’s sequence on a new HELLO without sending `1` again.

### Signature

Every command carries `mac` (see Signatures). A missing or wrong `mac` → `invalid_mac`, checked after the session and sequence and before anything in the command is used.

### PING

Client → sidecar. Used by `watchtower:status` and `watchtower-status`.

| Field | Type | Required |
| --- | --- | --- |
| `type` | string | `"ping"` |
| `protocol_version` | int | `2` |
| `session_id` | string | from WELCOME |
| `sequence` | int | `1` |
| `mac` | string | signed with an empty body |

Sidecar ACKs with `accepted: 0` (no records) and closes. PING does not require `batch_version`.

### TELEMETRY_BATCH

Client → sidecar.

| Field | Type | Required |
| --- | --- | --- |
| `type` | string | `"telemetry_batch"` |
| `protocol` | string | `"watchtower"` |
| `protocol_version` | int | `2` |
| `batch_version` | int | `2` |
| `session_id` | string | from WELCOME |
| `sequence` | int | `1` |
| `mac` | string | signed over the raw `records` bytes (see Signatures) |
| `records` | array | a JSON **list** of event objects; must be the **last** member of the frame body |

**Batch version:** `batch_version` must be `2` or the sidecar returns `unsupported_batch_version`. Bump this when the **batch envelope** changes, not when a single record field is added. Version 2 adds `mac` and requires `records` to be the last member, so both sides can take the records' bytes straight from the frame: the SDK encodes them once and splices them in (`Frame::encodeWithRawMember()`), and the sidecar signs and forwards that same slice (`Frame::rawLastMember()`) without re-encoding it.

**Records:** `records` must be a JSON **list**; an object or any other type is `invalid_records`. If it isn't the last member, the bytes the sidecar reads won't match the signature (`invalid_mac`).

**Max batch:** `count(records)` must be `≤ 500`. Otherwise `batch_too_large`. An empty list is acknowledged and not forwarded.

The SDK splits a larger buffer itself: it sends at most the `max_batch` from WELCOME per connection and opens new sessions for the rest, so a full buffer is never dropped.

Record objects are the existing Nightwatch-shaped payloads (`t`, `timestamp`, …). The sidecar forwards the raw `records` bytes to cloud ingest as received. It does not validate individual DTOs.

## ACK

Sidecar → client, then close.

| Field | Type | Meaning |
| --- | --- | --- |
| `type` | string | `"ack"` |
| `sequence` | int | Echo of the command sequence |
| `accepted` | int | Record count for a batch; `0` for PING |
| `rejected` | int | `0` on success |

The SDK requires `type=ack`, matching `sequence`, and `rejected === 0`. Any failure while sending is reported through `Watchtower::unrecoverableExceptionOccurred()` and never thrown into the application.

**What an ACK means.** With the spool on (the default), the sidecar appends the batch to its journal on disk before it sends the ACK (`agent/src/Journal.php`). The journal lines are removed once the upload they went into is done: delivered, kept in the spool after a failure, or dropped on purpose (rejected by ingest, over quota, ingestion paused). If the sidecar stops before that, the next start moves the journalled batches into the spool and sends them. Lines are flushed to the operating system but not fsync'd, so this covers a sidecar crash or kill, not a power loss. With the spool off (`WATCHTOWER_SPOOL_PATH=""`) there is no journal and an ACK only means the batch is in memory.

Each sidecar journals into its own `journal-*` directory inside the spool directory and holds a lock on it while running, so sidecars sharing a spool directory never recover each other's live batches.

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
| `unsupported_protocol` | Wrong `protocol` / `protocol_version` (including an app still on protocol 1) |
| `token_mismatch` | HELLO `mac` missing or invalid |
| `invalid_mac` | Command `mac` missing or invalid |
| `unexpected_type` | After WELCOME, not ping or telemetry_batch |
| `session_mismatch` | `session_id` ≠ issued session |
| `sequence_mismatch` | `sequence` ≠ expected (`1`) |
| `unsupported_batch_version` | `batch_version` ≠ `2` |
| `invalid_records` | `records` missing or not a JSON list |
| `batch_too_large` | More than 500 records |
| `invalid_frame` | Bad length prefix, frame over 16 MiB, or body not a JSON object |
| `idle_timeout` | Exchange not finished within 10 s |

The SDK surfaces the code, e.g. `The Watchtower agent rejected the connection [token_mismatch]`.

## Nightwatch wire (what we replaced)

Nightwatch used a colon-delimited payload, roughly `length:v1:{tokenHash}:{payload}`, with replies like `2:OK`. Watchtower 1.0.0 **does not** speak that format. Mixing a Nightwatch agent with a Watchtower sidecar (or the reverse) will fail handshake.

## Cloud ingest (not this TCP protocol)

After the sidecar accepts a batch it still authenticates to **your** Watchtower origin:

- `POST {WATCHTOWER_BASE_URL}/api/agent-auth`
- Bearer token = `WATCHTOWER_TOKEN`
- Response must include `token`, `expires_in` (int), `refresh_in` (int), `ingest_url` (string)

Retries use a backoff schedule in `IngestDetailsRepository`. The ingest token is refreshed when it expires (before the next batch, not only on the `refresh_in` timer), and a `401` from ingest drops the cached token so the next batch authenticates again. GitHub Actions does **not** call a real platform: tests fake HTTP or skip live auth when `WATCHTOWER_BASE_URL` contains `watchtower.test`.

## Upgrades (protocol 1 → 2)

The app and the sidecar ship in the same package, so they only disagree until the processes holding the old code restart:

- **App upgraded, sidecar still on 1:** the new HELLO still carries `token_hash`, so the old sidecar authenticates it, sees protocol 2, flushes and exits. The process manager restarts it on the new code.
- **Sidecar upgraded, app still on 1** (PHP-FPM, Octane or queue workers not yet restarted): the sidecar answers `unsupported_protocol`, keeps running, and logs that an application is still on the older agent. Those workers' data is refused until they restart (`php artisan queue:restart`, reload PHP-FPM or Octane). Accepting protocol 1 would keep the unsigned path open, so it isn't accepted.

## Why not protocol 1's token hash

Protocol 1 authenticated with the first 7 hex characters of `xxh128(token)`: 28 bits, the same on every connection, sent in plain text. It caught misconfiguration but couldn't stop a local process that reached the port, and a captured handshake could be replayed. Protocol 2 replaces it with the signatures above. Still keep the listen port on localhost or a private network.

## Tests that lock the protocol

| Area | Tests |
| --- | --- |
| Sidecar rejects | `agent/tests/Feature/ServerTest.php` |
| SDK frames | `tests/Unit/IngestTest.php` |
| Frame codec | `agent/tests/Unit/FrameBufferTest.php` |
| Status PING | `tests/Feature/Console/StatusCommandTest.php`, `agent/tests/Integration/HealthCheckTest.php` |

When changing the protocol, update **both** `Protocol.php` and `Mac.php` copies, fakes (`tests/SidecarReply.php`, `agent/tests/TcpServerFake.php`, `tests/FakeIngest.php`), this document, and `NOTICE.md`.

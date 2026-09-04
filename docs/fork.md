# Fork boundary and what 1.0.0 achieved

## Upstream

Watchtower Agent 1.0.0 is derived from [`laravel/nightwatch`](https://github.com/laravel/nightwatch) **v1.30.0** (MIT). Copyright for that code remains Taylor Otwell’s, as stated in `LICENSE.md` and `NOTICE.md`.

This git repository is Watchtower’s history, not Nightwatch’s. Do not treat Nightwatch tags (1.30.x, 1.x Nightwatch branches) as Watchtower package versions.

**Watchtower package version = 1.0.0.**  
**On-the-wire `protocol_version` = 1.**  
Those numbers are independent.

## Kept (MIT-derived, still the right instrumentation)

- Sensors (`src/Sensors/`)
- Records (`src/Records/`)
- Hooks (`src/Hooks/`)
- Sampling, filtering, redaction, execution stage / request & command state
- Compatibility shims for Laravel 10–13

Do not rewrite these to “make the protocol Watchtower.” Event JSON (`t` and fields) is the compatibility layer with the platform ingest path.

## Owned by Watchtower (1.0.0)

- Length-prefixed JSON frames (`{n}\\n{json}`)
- HELLO / WELCOME / telemetry_batch / PING / ACK / ERROR
- Sidecar enforcement: session id, sequence `1`, batch version `1`, max batch `500`
- Config and env prefix `WATCHTOWER_*`
- Destination: `WATCHTOWER_BASE_URL` only (no Nightwatch Cloud default)
- Composer authors: Watchtower
- Boxed `agent.phar` + `signature.txt` produced in CI and committed on `1.x` (human PRs and `1.x` pushes; Dependabot PRs do not write the phar)

## Deliberately not in 1.0.0

| Item | Status |
| --- | --- |
| HMAC / challenge on the socket | Deferred; still `token_hash` |
| New Watchtower event DTOs | Records keep Nightwatch-shaped fields |
| Long-lived TCP sessions with incrementing sequence | One command per connection |
| Live Watchtower in GitHub Actions | Fixture URL + skipped live auth tests |
| Nightwatch hosted ingest | Never used |

## Operational achievements around 1.0.0

- CI on `1.x` pushes and pull requests
- Package matrix: PHP 8.2–8.5, Laravel 10–13 (PHP 8.2 × Laravel 13 excluded)
- Agent tests: PHP 8.2–8.5 plus Docker health check against the local sidecar
- Pint + PHPStan on package and agent
- Phar rebuild + signature commit when Box output changes
- Docs for protocol, architecture, and development (this `docs/` tree)

## Attribution rule

Taylor Otwell / Laravel Nightwatch belong in **LICENSE** and **NOTICE**, not as Composer authors. New Watchtower protocol and branding copy should not claim to be Nightwatch.

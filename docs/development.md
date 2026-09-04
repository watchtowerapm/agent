# Development, testing, and maintenance

Package version lives in **`version.txt`** (currently `1.0.0`). Keep `composer.json` `"version"` in sync. The sidecar prints this string after “Listening on …”. HELLO may send it as `agent_version`.

Default git branch: **`1.x`**.

## Layout

Work in this repository (`watchtowerapm/agent`), not the Watchtower platform repo, unless you are changing the cloud API.

```
src/                  Laravel package
tests/                Package PHPUnit (Orchestra Testbench)
config/watchtower.php
agent/src/            Sidecar
agent/tests/          Sidecar PHPUnit
agent/build/          agent.phar + signature.txt (committed artifacts)
docs/                 This documentation
.github/workflows/pull_request.yml
```

## Prerequisites

- PHP 8.2+ with extensions used in CI (`mbstring`, `curl`, sockets/`pcntl` for the agent, `redis` for some package tests, etc.)
- Composer 2
- Docker (sidecar image, phar build via `agent/build.sh`)
- Optional: Redis on `6379` for Horizon/redis feature tests locally

You do **not** need a live Watchtower instance to develop or to run GitHub Actions.

## Install

```bash
composer install
cd agent && composer install && cd ..
```

Copy env from `phpunit.xml.dist` mentally: tests already set

```
WATCHTOWER_TOKEN=fakepkxoLBIOgPE0PZWadR0Ge1zHBh31ATOzXN9bBboZ
WATCHTOWER_BASE_URL=https://watchtower.test
```

`watchtower.test` does not resolve. That is intentional. Sidecar **listen** tests still run. Tests that authenticate to the platform (`AgentCommandTest`, `PharTest`) **skip** when the base URL contains `watchtower.test`.

## Package tests (Laravel SDK)

```bash
composer test
# or
vendor/bin/phpunit --order-by=random
```

Helpers:

- `fakeIngest()` — capture frames without a real sidecar
- `Http::preventStrayRequests()` in `TestCase` — no accidental cloud calls
- Feature tests under `tests/Feature/`, unit under `tests/Unit/`

Filter examples:

```bash
vendor/bin/phpunit --filter IngestTest
vendor/bin/phpunit --filter ServerTest   # this one is under agent/
```

## Agent / sidecar tests

```bash
cd agent
composer test
# or
vendor/bin/phpunit --order-by=random
```

Protocol enforcement: `agent/tests/Feature/ServerTest.php`.  
Fakes rewrite placeholder `sess_test` to the issued session (`PendingConnection`).

## Lint and static analysis

Package (repo root):

```bash
vendor/bin/pint --test
vendor/bin/pint --config=pint.ci.json --test   # CI-only ruleset also runs in Actions
vendor/bin/phpstan --verbose
```

Agent:

```bash
cd agent
vendor/bin/pint --test
vendor/bin/phpstan --verbose
```

Pint uses `global_namespace_import` and `native_function_invocation`. Format before push; **Coding standards** is a required CI job and skips later jobs if it fails.

`composer ci` / `composer lint` at the root run package checks. Agent has its own `composer ci`.

## Local sidecar

```bash
# needs WATCHTOWER_TOKEN and WATCHTOWER_BASE_URL in the environment
php artisan watchtower:agent
# or
vendor/bin/testbench watchtower:agent
```

Workbench:

```bash
composer serve    # testbench serve
composer agent    # testbench watchtower:agent
composer dev      # both via concurrently
```

Without a reachable `WATCHTOWER_BASE_URL`, the process still **listens** after startup but logs authentication failures and will not successfully POST ingest. Local protocol work can use the listen port plus `php agent/watchtower-status` (token required).

Docker:

```bash
docker build -f agent/Dockerfile -t watchtower-agent .
docker run --rm -e WATCHTOWER_TOKEN=… -e WATCHTOWER_BASE_URL=https://your-origin.example \
  -p 2407:2407 watchtower-agent
```

Image `ENV WATCHTOWER_INGEST_URI=0.0.0.0:2407`. Healthcheck runs `php watchtower-status`.

## Building the phar

```bash
cd agent
bash build.sh
# or
composer build
```

Requires Docker (Box in `agent/docker/Dockerfile.build`). Writes:

- `agent/build/agent.phar`
- `agent/build/signature.txt` (Box signature; sidecar watches this file and shuts down if it changes)

`watchtower:agent` prefers the phar when the file exists. After protocol changes, rebuild so production Artisan does not run a stale phar.

### CI phar policy

Job **Build agent** always compiles.

| Event | Commit phar + signature? |
| --- | --- |
| Human pull request | Yes, onto the PR branch (`Bump agent version to …`) |
| Push to `1.x` | Yes, onto `1.x` |
| Dependabot PR | No write; job **fails** if the build dirties the tree |

`GITHUB_TOKEN` commits on `1.x` do not start a second workflow. Tests still run on that push even if a phar commit is produced.

After a protocol or sidecar source change, wait for that bump commit before tagging a release so the published tree contains a matching phar.

## GitHub Actions

Workflow: `.github/workflows/pull_request.yml` (`CI`).

Triggers: `pull_request`, `push` to `1.x`, `workflow_dispatch`.

Order: package PHPStan → agent PHPStan → Pint → build phar → package matrix + agent matrix.

Package matrix: PHP 8.2–8.5 × Laravel 10–13 × prefer-lowest, excluding PHP 8.2 + Laravel 13. `fail-fast: false`.

Secrets `WATCHTOWER_TOKEN` / `WATCHTOWER_BASE_URL` are optional. Empty secrets fall back to the same fixtures as `phpunit.xml.dist`. **Do not** add a real Watchtower to GitHub for CI.

## Changing the protocol

1. Update **both** `Protocol.php` files and both `Frame.php` files if framing changes.
2. Update `Server.php` (enforcement) and `src/Ingest.php` (client).
3. Update fakes: `TcpServerFake`, `SidecarReply`, `FakeIngest`, `watchtower-status`.
4. Extend `ServerTest` / `IngestTest`.
5. Rebuild phar (CI will commit on `1.x`).
6. Update `docs/protocol.md`, `NOTICE.md`, and `CHANGELOG.md`.

Do not bump **package** version to 2.0.0 just because `protocol_version` is 1. Bump `protocol_version` when HELLO/WELCOME meaning changes; bump `batch_version` when the batch envelope changes; bump `version.txt` for the Composer package.

## Changing sensors / records

Stay compatible with existing `t` payloads unless the platform is updated in lockstep. Prefer additive fields. Keep hooks `final` + `@internal`, wrap in try/catch, `handled: true`.

Coding conventions: `AGENTS.md` (this repo).

## Releases

1. Set `version.txt` and `composer.json` version.
2. `CHANGELOG.md` entry.
3. Ensure CI on `1.x` is green and phar signature commit is present if agent sources changed.
4. Tag from `1.x` (e.g. `v1.0.0`) when you publish to Packagist.

## Security reporting

Do not file public issues for vulnerabilities. Use GitHub Security Advisories on `watchtowerapm/agent`. See `.github/SECURITY.md`.

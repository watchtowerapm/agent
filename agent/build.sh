#!/usr/bin/env bash

set -euo pipefail

mkdir -p build

docker build --platform=linux/amd64 -t agent-builder -f docker/Dockerfile.build .

run_builder() {
    docker run --rm --platform=linux/amd64 -v "$(pwd)":/app "$@"
}

run_builder --entrypoint composer agent-builder install --prefer-dist --no-dev --no-interaction --classmap-authoritative

# Default entrypoint is /box.phar (not on PATH). Compile as root, then chown
# so the CI user can record the signature and commit the artifacts.
run_builder agent-builder compile
test -f build/agent.phar

signature="$(run_builder --entrypoint /box.phar agent-builder info:signature build/agent.phar)"
run_builder --entrypoint sh agent-builder -c "chown -R $(id -u):$(id -g) /app/build"

printf '%s\n' "${signature}" > build/signature.txt
test -s build/signature.txt

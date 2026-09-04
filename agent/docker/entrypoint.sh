#!/bin/sh

shutdown() {
    echo "Received signal, shutting down..."
    if [ -n "$child" ]; then
        kill -TERM "$child" 2>/dev/null
        wait "$child"
    fi
    exit 0
}

trap shutdown TERM INT

php /watchtower-agent/agent/src/agent.php &
child=$!

wait "$child"

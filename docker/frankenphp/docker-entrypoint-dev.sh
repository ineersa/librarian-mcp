#!/bin/bash
set -e

# Ensure /etc/passwd (and group) has an entry for the mounted /app owner UID/GID so
# `docker compose exec -u $(id -u)` shells show a real username instead of "I have no name!".
if [[ -d /app ]]; then
    APP_UID=$(stat -c '%u' /app 2>/dev/null || echo 0)
    APP_GID=$(stat -c '%g' /app 2>/dev/null || echo 0)
    if [[ "${APP_UID}" != "0" ]] && ! getent passwd "${APP_UID}" >/dev/null 2>&1; then
        if ! getent group "${APP_GID}" >/dev/null 2>&1; then
            echo "symfony-host:x:${APP_GID}:" >>/etc/group
        fi
        echo "symfony-dev:x:${APP_UID}:${APP_GID}:Dev shell:/app:/bin/bash" >>/etc/passwd
    fi

    # Keep mutable project directories owned by the host-mapped user.
    # FrankenPHP runs as root in this container and may create root-owned files
    # (for example in var/cache/dev), which then breaks non-root CLI tasks.
    if [[ "${APP_UID}" != "0" ]]; then
        mkdir -p /app/var/cache /app/var/log /app/data
        chown -R "${APP_UID}:${APP_GID}" /app/var /app/data 2>/dev/null || true
    fi
fi

# Start FrankenPHP in the background without --watch flag
# (we'll use our own file watcher)
frankenphp run --config /etc/caddy/Caddyfile &
FRANKENPHP_PID=$!

# Start the file watcher in the background
/usr/local/bin/watch-and-restart.sh &
WATCHER_PID=$!

echo "================================================"
echo "FrankenPHP started with PID: $FRANKENPHP_PID"
echo "File watcher started with PID: $WATCHER_PID"
echo "================================================"
echo "App is accessible at: http://localhost:8080"
echo "Admin API: http://localhost:2019"
echo "================================================"

# Function to handle shutdown
shutdown() {
    echo "Shutting down..."
    kill -TERM $FRANKENPHP_PID 2>/dev/null || true
    kill -TERM $WATCHER_PID 2>/dev/null || true
    wait $FRANKENPHP_PID 2>/dev/null || true
    wait $WATCHER_PID 2>/dev/null || true
    echo "Shutdown complete"
    exit 0
}

# Trap signals
trap shutdown SIGTERM SIGINT

# Wait for both processes
wait -n $FRANKENPHP_PID $WATCHER_PID

# If one process exited, shut down the other
shutdown

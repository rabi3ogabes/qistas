#!/bin/sh
# Starts the Qistas container: makes sure there is an application key, brings the database schema up to date,
# then hands over to the web server. Runs on every start, so it must be quick and safe to repeat.
set -eu

cd /app

# --- The application key encrypts sessions and customers' national IDs. Losing it makes that data unreadable, so
# --- a hosted deployment must supply one (APP_KEY) and never silently invent a new one on each start.
if [ -z "${APP_KEY:-}" ]; then
    if [ "${QISTAS_ALLOW_GENERATED_KEY:-0}" = "1" ]; then
        # Local use only (docker compose): create a key once and keep it in the volume mounted at /data/qistas.
        KEY_FILE=/data/qistas/app.key
        if [ ! -s "$KEY_FILE" ]; then
            php artisan key:generate --show --no-interaction > "$KEY_FILE"
        fi
        APP_KEY="$(cat "$KEY_FILE")"
        export APP_KEY
    else
        echo "Qistas cannot start: APP_KEY is not set." >&2
        echo "Generate one with:  php artisan key:generate --show   and add it as the APP_KEY environment variable." >&2
        exit 1
    fi
fi

# --- Schema. Safe when several copies start at once: the command takes a database lock and retries.
if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
    # DEMO_DATA=true (docker compose) also creates a demo workspace to look around in. Never set it on a real site.
    DEMO_FLAG=""
    if [ "${DEMO_DATA:-false}" = "true" ]; then DEMO_FLAG="--demo"; fi

    attempt=1
    until php artisan qistas:setup $DEMO_FLAG --no-interaction; do
        if [ "$attempt" -ge 4 ]; then
            echo "Qistas cannot start: the database could not be set up (see the messages above)." >&2
            exit 1
        fi
        attempt=$((attempt + 1))
        echo "Database setup did not finish; trying again in 3 seconds (attempt $attempt of 4)..." >&2
        sleep 3
    done
fi

exec "$@"

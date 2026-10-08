#!/bin/sh
# Starts the Qistas container. Two ways to run:
#
#   REAL     APP_KEY and a database (DB_URL, or DB_HOST) are set. The schema is brought up to date and the app runs
#            on that database. This is what a live site uses.
#
#   DEMO     Neither is set. The app starts on a small built-in demo database (a demo business with a login shown
#            on the sign-in page), with a key that was generated when the image was built. Nothing is stored for
#            long: every copy of the app has its own temporary data, and a banner says so. It lets a deployment be
#            looked at before any secret has been configured; it must never hold real customers.
#
# Setting only half of the real configuration is an error, never a silent switch to the demo.
set -eu

cd /app

if [ -z "${APP_KEY:-}" ] && [ -z "${DB_URL:-}" ] && [ -z "${DB_HOST:-}" ] && [ "${QISTAS_ALLOW_GENERATED_KEY:-0}" != "1" ]; then
    echo "Qistas: no APP_KEY and no database are configured, so this is a DEMO with temporary data." >&2
    echo "        Set APP_KEY and DB_URL to run for real (see docs/DEPLOY.md)." >&2

    APP_KEY="$(cat /app/storage/app/demo.key)"
    export APP_KEY
    export QISTAS_DEMO=true
    export DB_CONNECTION=sqlite
    export DB_DATABASE=/tmp/qistas-demo.sqlite
    # State that must outlive a request lives in the (signed, encrypted) cookie, so that any copy of the app
    # can serve any visitor; there is no shared database to keep it in.
    export SESSION_DRIVER=cookie
    export CACHE_STORE=array

    if [ ! -f "$DB_DATABASE" ]; then
        cp /app/database/demo.sqlite "$DB_DATABASE"
    fi

    exec "$@"
fi

# --- The application key encrypts sessions and customers' national IDs. Losing it makes that data unreadable, so
# --- a hosted deployment must supply one (APP_KEY) and never silently invent a new one on each start.
if [ -z "${APP_KEY:-}" ]; then
    if [ "${QISTAS_ALLOW_GENERATED_KEY:-0}" = "1" ]; then
        # Local use only (docker compose): create a key once and keep it in the volume mounted at /data/qistas.
        KEY_FILE=/data/qistas/app.key
        if [ ! -s "$KEY_FILE" ]; then
            php artisan key:generate --show --no-ansi --no-interaction > "$KEY_FILE"
        fi
        APP_KEY="$(cat "$KEY_FILE")"
        export APP_KEY
    else
        echo "Qistas cannot start: DB_URL is set but APP_KEY is not." >&2
        echo "Generate a key with:  php artisan key:generate --show   and add it as the APP_KEY environment variable." >&2
        exit 1
    fi
fi

if [ "${DB_CONNECTION:-pgsql}" = "pgsql" ] && [ -z "${DB_URL:-}" ] && [ -z "${DB_HOST:-}" ]; then
    echo "Qistas cannot start: APP_KEY is set but no database is. Add the DB_URL environment variable." >&2
    exit 1
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

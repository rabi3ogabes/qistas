#!/bin/sh
# Smoke test of a running Qistas web app: the public pages answer, the app keeps guests out, and assets load.
# usage: scripts/smoke-app.sh <base-url> [wait-seconds]
set -eu

BASE="${1:?usage: smoke-app.sh <base-url> [wait-seconds]}"
WAIT="${2:-120}"

# A deployment can take a moment to answer (a container that is starting up): wait for /up.
elapsed=0
until curl -fsS -o /dev/null "$BASE/up" 2>/dev/null; do
    if [ "$elapsed" -ge "$WAIT" ]; then
        echo "::error::$BASE/up did not answer within ${WAIT}s"
        exit 1
    fi
    sleep 3
    elapsed=$((elapsed + 3))
done

failed=0
check() {
    code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE$1")"
    if [ "$code" = "$2" ]; then echo "ok    $1 -> $code"; else echo "FAIL  $1 -> $code (wanted $2)"; failed=1; fi
}

for path in / /pricing /terms /privacy /login /register /sitemap.xml /robots.txt; do
    check "$path" 200
done
check /app 302
check /definitely-not-a-page 404

# The built stylesheet and script are served (a build that lost its assets looks fine until it is opened).
home="$(curl -s "$BASE/")"
for pattern in '/build/assets/[^"]*\.css' '/build/assets/[^"]*\.js'; do
    asset="$(echo "$home" | grep -o "$pattern" | head -1)"
    if [ -n "$asset" ]; then check "$asset" 200; else echo "FAIL  no asset matching $pattern on the home page"; failed=1; fi
done

[ "$failed" = "0" ] || { echo "::error::smoke test failed for $BASE"; exit 1; }
echo "smoke test passed for $BASE"

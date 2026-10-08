#!/bin/sh
# Signs in as the demo account over HTTP (like a browser would) and checks the dashboard. usage: e2e.sh <base-url>
set -eu
BASE="$1"
JAR="$(mktemp)"

echo "GET /up                 -> $(curl -s -o /dev/null -w '%{http_code}' "$BASE/up")"
echo "GET /                   -> $(curl -s -o /dev/null -w '%{http_code}' "$BASE/")"
HOME_HTML="$(curl -s -c "$JAR" -b "$JAR" "$BASE/")"
echo "home has Sign in / Start free / demo banner: $(echo "$HOME_HTML" | grep -c 'Sign in') / $(echo "$HOME_HTML" | grep -c 'Start free') / $(echo "$HOME_HTML" | grep -c 'This is a demo')"

LOGIN_HTML="$(curl -s -c "$JAR" -b "$JAR" "$BASE/login")"
TOKEN="$(echo "$LOGIN_HTML" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')"
echo "login page token found: $([ -n "$TOKEN" ] && echo yes || echo NO)"
echo "login shows demo account: $(echo "$LOGIN_HTML" | grep -c 'demo@qistas.test')"

CODE="$(curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}' -c "$JAR" -b "$JAR" -X POST "$BASE/login" \
  --data-urlencode "_token=$TOKEN" --data-urlencode "email=demo@qistas.test" --data-urlencode "password=Demo!Passw0rd2026")"
echo "POST /login             -> $CODE"

for path in /app /app/customers /app/contracts?status=all /app/payments; do
  OUT="$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$BASE$path")"
  echo "GET $path -> $OUT"
done
echo "dashboard mentions a customer: $(curl -s -c "$JAR" -b "$JAR" "$BASE/app/customers" | grep -c 'Omar Khalil')"
rm -f "$JAR"

#!/bin/sh
# The whole demo in one command: the shop with Tool Guard, then the same shop without it,
# each attacked by the same scripted agent over MCP. No API key, no database.
#   sh demo/run.sh
#   docker run --rm -v "$PWD":/app -w /app composer:2 sh demo/run.sh
set -e
cd "$(dirname "$0")/.."
[ -d vendor ] || composer install --no-interaction --no-progress --quiet

serve() { # port, extra env
    env $2 php -S "127.0.0.1:$1" -t demo/public >/dev/null 2>&1 &
    PIDS="$PIDS $!"
    i=0; until php -r "exit(@fsockopen('127.0.0.1', $1) ? 0 : 1);"; do i=$((i+1)); [ $i -gt 50 ] && exit 1; sleep 0.1; done
}
PIDS=""
trap 'kill $PIDS 2>/dev/null || true' EXIT

serve 8000 ""
serve 8001 "DEMO_UNGUARDED=1"

echo "=== With Tool Guard ==="
php demo/bin/console demo:attack --url=http://127.0.0.1:8000
echo
echo "=== The same shop, the same agent, without Tool Guard (the prompt is the only lock) ==="
php demo/bin/console demo:attack --url=http://127.0.0.1:8001

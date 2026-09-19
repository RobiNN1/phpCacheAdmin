#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
app_dir="${PCA_APP_DIR:-$script_dir/.app}"
docroot="${PCA_DOCROOT:-/tmp/phpCacheAdmin}"
port="${PCA_PORT:-8123}"
url="http://127.0.0.1:$port"
dashboards=(redis memcached opcache apcu realpath)

if [ ! -f "$app_dir/index.php" ]; then
    echo "No phpCacheAdmin in $app_dir." >&2
    echo "Clone it there (git clone https://github.com/RobiNN1/phpCacheAdmin.git $script_dir/.app) or set PCA_APP_DIR." >&2
    exit 1
fi

case "$docroot" in
    /|/tmp|"") echo "PCA_DOCROOT must be its own directory, it is emptied on every run." >&2; exit 1 ;;
esac

workdir="$(mktemp -d)"
server_pid=""

cleanup() {
    if [ -n "$server_pid" ]; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi

    rm -rf "$workdir" "$docroot"
}

trap cleanup EXIT

rm -rf "$docroot"
mkdir -p "$docroot/tmp"
tar -C "$app_dir" -cf - \
    --exclude=.git --exclude=.idea --exclude=.DS_Store \
    --exclude=node_modules --exclude=vendor --exclude=tmp \
    --exclude=config.php --exclude=.env . | tar -C "$docroot" -xf -
cp "$script_dir/seed.php" "$docroot/seed.php"

echo "==> Serving $app_dir on $url"

php -S "127.0.0.1:$port" -t "$docroot" \
    -d opcache.enable=1 \
    -d opcache.enable_cli=1 \
    -d opcache.memory_consumption=128 \
    -d opcache.interned_strings_buffer=8 \
    -d opcache.max_accelerated_files=10000 \
    -d apc.enabled=1 \
    -d apc.enable_cli=1 \
    -d realpath_cache_size=4M \
    -d realpath_cache_ttl=3600 \
    -d memory_limit=256M \
    >"$workdir/server.log" 2>&1 &
server_pid=$!

for _ in $(seq 1 50); do
    if curl -fs -o /dev/null "$url/assets/favicon.png"; then
        break
    fi

    sleep 0.2
done

if ! kill -0 "$server_pid" 2>/dev/null; then
    echo "The built-in server did not start:" >&2
    cat "$workdir/server.log" >&2
    exit 1
fi

echo "==> Seeding"

curl -sS -o "$workdir/seed.json" "$url/seed.php" || true
cat "$workdir/seed.json"

if ! grep -q '"status": "ok"' "$workdir/seed.json"; then
    echo "Seeding failed." >&2
    exit 1
fi

echo "==> Warming up"

for _ in $(seq 1 "${PCA_WARMUP:-5}"); do
    for dashboard in "${dashboards[@]}"; do
        curl -fs -o /dev/null "$url/?dashboard=$dashboard" || true
    done
done

if [ ! -d "$script_dir/node_modules" ]; then
    echo "==> Installing capture dependencies"
    (cd "$script_dir" && npm install --no-audit --no-fund && npx playwright install chromium)
fi

echo "==> Capturing"

PCA_URL="$url" node "$script_dir/capture.mjs"

#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${BENCHMARK_REVERB_APP_DIR:-$ROOT_DIR/app}"

if [[ ! -f "$APP_DIR/artisan" ]]; then
    echo "Laravel app not found. Run: bash $ROOT_DIR/setup.sh" >&2
    exit 1
fi

cd "$APP_DIR"
exec php -d memory_limit=-1 artisan reverb:start --host=127.0.0.1 --port=8991

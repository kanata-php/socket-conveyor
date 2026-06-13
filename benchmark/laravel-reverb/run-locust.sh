#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCUST_DIR="$ROOT_DIR/../locust"

cd "$LOCUST_DIR"

if [[ -f .venv/bin/activate ]]; then
    # shellcheck disable=SC1091
    source .venv/bin/activate
fi

export BENCHMARK_APP_ID="${BENCHMARK_APP_ID:-local-app}"
export BENCHMARK_APP_KEY="${BENCHMARK_APP_KEY:-local-key}"
export BENCHMARK_APP_SECRET="${BENCHMARK_APP_SECRET:-local-secret}"

exec locust -f locustfile.py \
    # --headless \
    --users "${BENCHMARK_USERS:-100}" \
    --spawn-rate "${BENCHMARK_SPAWN_RATE:-10}" \
    --run-time "${BENCHMARK_RUN_TIME:-2m}" \
    --host "${BENCHMARK_HOST:-http://127.0.0.1:8991}" \
    --csv "${BENCHMARK_CSV_PREFIX:-../results/reverb-laravel13}"

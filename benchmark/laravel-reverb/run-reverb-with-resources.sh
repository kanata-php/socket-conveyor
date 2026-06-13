#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
REVERB_DIR="$ROOT_DIR/benchmark/laravel-reverb"
APP_DIR="${BENCHMARK_REVERB_APP_DIR:-$REVERB_DIR/app}"
LOCUST_DIR="$ROOT_DIR/benchmark/locust"
RESULTS_DIR="$ROOT_DIR/benchmark/results"
mkdir -p "$RESULTS_DIR"

HOST="${BENCHMARK_HOST:-http://127.0.0.1:8991}"
CSV_PREFIX="${BENCHMARK_CSV_PREFIX:-$RESULTS_DIR/reverb-laravel13}"
RESOURCE_REPORT="${BENCHMARK_RESOURCE_REPORT:-$RESULTS_DIR/reverb-laravel13-resources.csv}"
SERVER_LOG="${BENCHMARK_SERVER_LOG:-$RESULTS_DIR/reverb-laravel13-server.log}"

if [[ ! -f "$APP_DIR/artisan" ]]; then
    echo "Laravel app not found. Run: bash $REVERB_DIR/setup.sh" >&2
    exit 1
fi

collect_descendants() {
    local parent="$1"
    local child

    pgrep -P "$parent" 2>/dev/null | while read -r child; do
        printf '%s\n' "$child"
        collect_descendants "$child"
    done
}

sample_resources() {
    local root_pid="$1"
    local report="$2"

    printf 'timestamp,pids,cpu_percent,rss_kb,vsz_kb\n' > "$report"

    while kill -0 "$root_pid" 2>/dev/null; do
        mapfile -t pids < <(printf '%s\n' "$root_pid"; collect_descendants "$root_pid" | sort -n | uniq)
        local pid_list
        pid_list="$(IFS=,; echo "${pids[*]}")"

        ps -o pcpu= -o rss= -o vsz= -p "$pid_list" 2>/dev/null \
            | awk -v ts="$(date --iso-8601=seconds)" -v count="${#pids[@]}" '
                { cpu += $1; rss += $2; vsz += $3 }
                END { printf "%s,%d,%.2f,%d,%d\n", ts, count, cpu, rss, vsz }
            ' >> "$report"

        sleep 1
    done
}

cleanup() {
    [[ -n "${SAMPLER_PID:-}" ]] && kill "$SAMPLER_PID" 2>/dev/null || true
    [[ -n "${SERVER_PID:-}" ]] && kill "$SERVER_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

cd "$APP_DIR"
php -d memory_limit=-1 artisan reverb:start --host=127.0.0.1 --port=8991 > "$SERVER_LOG" 2>&1 &
SERVER_PID="$!"

sleep "${BENCHMARK_STARTUP_SECONDS:-2}"
sample_resources "$SERVER_PID" "$RESOURCE_REPORT" &
SAMPLER_PID="$!"

cd "$LOCUST_DIR"
if [[ -f .venv/bin/activate ]]; then
    # shellcheck disable=SC1091
    source .venv/bin/activate
fi

export BENCHMARK_APP_ID="${BENCHMARK_APP_ID:-local-app}"
export BENCHMARK_APP_KEY="${BENCHMARK_APP_KEY:-local-key}"
export BENCHMARK_APP_SECRET="${BENCHMARK_APP_SECRET:-local-secret}"

# Distribute the load generator across cores so Locust itself is not the
# bottleneck. Without this a single Locust process saturates one core and caps
# throughput well below what the server can handle, making the comparison a
# measure of the generator rather than the server.
LOCUST_EXTRA=()
[[ -n "${BENCHMARK_PROCESSES:-}" ]] && LOCUST_EXTRA+=(--processes "${BENCHMARK_PROCESSES}")

locust -f locustfile.py \
    --headless \
    --users "${BENCHMARK_USERS:-100}" \
    --spawn-rate "${BENCHMARK_SPAWN_RATE:-10}" \
    --run-time "${BENCHMARK_RUN_TIME:-2m}" \
    --reset-stats \
    --stop-timeout "${BENCHMARK_STOP_TIMEOUT:-2}" \
    "${LOCUST_EXTRA[@]}" \
    --host "$HOST" \
    --csv "$CSV_PREFIX"

echo "Resource report: $RESOURCE_REPORT"
echo "Locust CSV prefix: $CSV_PREFIX"
echo "Server log: $SERVER_LOG"

#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOCUST_DIR="$ROOT_DIR/benchmark/locust"
RESULTS_DIR="$ROOT_DIR/benchmark/results"
mkdir -p "$RESULTS_DIR"

HOST="${BENCHMARK_HOST:-http://127.0.0.1:8990}"
CSV_PREFIX="${BENCHMARK_CSV_PREFIX:-$RESULTS_DIR/conveyor}"
RESOURCE_REPORT="${BENCHMARK_RESOURCE_REPORT:-$RESULTS_DIR/conveyor-resources.csv}"
SERVER_LOG="${BENCHMARK_SERVER_LOG:-$RESULTS_DIR/conveyor-server.log}"

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

cd "$ROOT_DIR"
php benchmark/server-conveyor.php > "$SERVER_LOG" 2>&1 &
SERVER_PID="$!"

sleep "${BENCHMARK_STARTUP_SECONDS:-2}"
sample_resources "$SERVER_PID" "$RESOURCE_REPORT" &
SAMPLER_PID="$!"

cd "$LOCUST_DIR"
if [[ -f .venv/bin/activate ]]; then
    # shellcheck disable=SC1091
    source .venv/bin/activate
fi

locust -f locustfile.py \
    --headless \
    --users "${BENCHMARK_USERS:-100}" \
    --spawn-rate "${BENCHMARK_SPAWN_RATE:-10}" \
    --run-time "${BENCHMARK_RUN_TIME:-2m}" \
    --host "$HOST" \
    --csv "$CSV_PREFIX"

echo "Resource report: $RESOURCE_REPORT"
echo "Locust CSV prefix: $CSV_PREFIX"
echo "Server log: $SERVER_LOG"

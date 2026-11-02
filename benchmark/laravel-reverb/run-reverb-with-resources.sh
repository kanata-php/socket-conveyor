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
LOCUST_RESOURCE_REPORT="${BENCHMARK_LOCUST_RESOURCE_REPORT:-$RESULTS_DIR/reverb-laravel13-locust-resources.csv}"
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

# Invoked by the EXIT/INT/TERM cleanup handler.
# shellcheck disable=SC2329
terminate_process_tree() {
    local root="${1:-}"
    [[ -z "$root" ]] && return 0

    mapfile -t pids < <(collect_descendants "$root"; printf '%s\n' "$root")
    # Ask the root process to shut down first. OpenSwoole, Reverb, and Locust
    # all have supervisors that clean up their own child processes; signalling
    # every child at once can make the supervisor report shutdown noise even
    # when the workload completed successfully.
    kill "$root" 2>/dev/null || true

    local deadline=$((SECONDS + ${BENCHMARK_PROCESS_STOP_TIMEOUT:-10}))
    while (( SECONDS < deadline )); do
        local any_alive=false
        for pid in "${pids[@]}"; do
            if kill -0 "$pid" 2>/dev/null; then
                any_alive=true
                break
            fi
        done

        [[ "$any_alive" == false ]] && return 0
        sleep 1
    done

    mapfile -t pids < <(collect_descendants "$root"; printf '%s\n' "$root")
    for pid in "${pids[@]}"; do
        kill "$pid" 2>/dev/null || true
    done

    sleep 1

    printf 'Timed out waiting for process tree rooted at %s to exit cleanly\n' "$root" >&2
}

wait_for_port_free() {
    local host="$1"
    local port="$2"
    local deadline=$((SECONDS + ${BENCHMARK_PORT_FREE_TIMEOUT:-30}))

    while port_in_use "$host" "$port"; do
        if (( SECONDS >= deadline )); then
            echo "Port $host:$port is still in use" >&2
            return 1
        fi
        sleep 1
    done
}

port_in_use() {
    local host="$1"
    local port="$2"

    if command -v ss >/dev/null 2>&1 && ss -H -ltn "sport = :$port" 2>/dev/null | grep -q .; then
        return 0
    fi

    (echo > "/dev/tcp/$host/$port") >/dev/null 2>&1
}

wait_for_port_accepting() {
    local host="$1"
    local port="$2"
    local deadline=$((SECONDS + ${BENCHMARK_STARTUP_TIMEOUT:-30}))

    while ! (echo > "/dev/tcp/$host/$port") >/dev/null 2>&1; do
        if ! kill -0 "$SERVER_PID" 2>/dev/null; then
            echo "Server process exited before $host:$port became reachable" >&2
            return 1
        fi

        if (( SECONDS >= deadline )); then
            echo "Timed out waiting for server at $host:$port" >&2
            return 1
        fi

        sleep 1
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
            | awk -v ts="$(date +%Y-%m-%dT%H:%M:%S%z)" -v count="${#pids[@]}" '
                { cpu += $1; rss += $2; vsz += $3 }
                END { printf "%s,%d,%.2f,%d,%d\n", ts, count, cpu, rss, vsz }
            ' >> "$report"

        sleep 1
    done
}

# Invoked indirectly by trap.
# shellcheck disable=SC2329
cleanup() {
    [[ -n "${LOCUST_SAMPLER_PID:-}" ]] && terminate_process_tree "$LOCUST_SAMPLER_PID"
    [[ -n "${LOCUST_PID:-}" ]] && terminate_process_tree "$LOCUST_PID"
    [[ -n "${SAMPLER_PID:-}" ]] && terminate_process_tree "$SAMPLER_PID"
    [[ -n "${SERVER_PID:-}" ]] && terminate_process_tree "$SERVER_PID"
}
trap cleanup EXIT INT TERM

cd "$APP_DIR"
SERVER_HOST=127.0.0.1
SERVER_PORT=8991
wait_for_port_free "$SERVER_HOST" "$SERVER_PORT"
php -d memory_limit=-1 artisan reverb:start --host="$SERVER_HOST" --port="$SERVER_PORT" > "$SERVER_LOG" 2>&1 &
SERVER_PID="$!"

sleep "${BENCHMARK_STARTUP_SECONDS:-2}"
wait_for_port_accepting "$SERVER_HOST" "$SERVER_PORT"
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
# See run-conveyor-with-resources.sh: BENCHMARK_RESET_STATS=false keeps full
# connect/subscribe samples (compare throughput via *_stats_history.csv instead).
[[ "${BENCHMARK_RESET_STATS:-true}" == "true" ]] && LOCUST_EXTRA+=(--reset-stats)

locust -f locustfile.py \
    --headless \
    --users "${BENCHMARK_USERS:-100}" \
    --spawn-rate "${BENCHMARK_SPAWN_RATE:-10}" \
    --run-time "${BENCHMARK_RUN_TIME:-2m}" \
    --stop-timeout "${BENCHMARK_STOP_TIMEOUT:-2}" \
    "${LOCUST_EXTRA[@]}" \
    --host "$HOST" \
    --csv "$CSV_PREFIX" &
LOCUST_PID="$!"

sample_resources "$LOCUST_PID" "$LOCUST_RESOURCE_REPORT" &
LOCUST_SAMPLER_PID="$!"

set +e
wait "$LOCUST_PID"
LOCUST_STATUS="$?"
set -e

kill "$LOCUST_SAMPLER_PID" 2>/dev/null || true
LOCUST_SAMPLER_PID=""

echo "Resource report: $RESOURCE_REPORT"
echo "Locust resource report: $LOCUST_RESOURCE_REPORT"
echo "Locust CSV prefix: $CSV_PREFIX"
echo "Server log: $SERVER_LOG"

exit "$LOCUST_STATUS"

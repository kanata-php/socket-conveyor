#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUN_ID="${BENCHMARK_RUN_ID:-$(date -u +%Y%m%dT%H%M%SZ)}"
RUN_ROOT="${BENCHMARK_RUN_ROOT:-$ROOT_DIR/benchmark/results/runs/$RUN_ID}"
TRIALS="${BENCHMARK_TRIALS:-5}"
MIN_VALID_TRIALS="${BENCHMARK_MIN_VALID_TRIALS:-5}"

mkdir -p "$RUN_ROOT"

export BENCHMARK_PROCESSES="${BENCHMARK_PROCESSES:-6}"
export BENCHMARK_USERS="${BENCHMARK_USERS:-100}"
export BENCHMARK_SPAWN_RATE="${BENCHMARK_SPAWN_RATE:-10}"
export BENCHMARK_RUN_TIME="${BENCHMARK_RUN_TIME:-2m}"
export BENCHMARK_PAYLOAD_BYTES="${BENCHMARK_PAYLOAD_BYTES:-256}"
export BENCHMARK_RESET_STATS="${BENCHMARK_RESET_STATS:-true}"
export BENCHMARK_STOP_TIMEOUT="${BENCHMARK_STOP_TIMEOUT:-2}"
export BENCHMARK_STARTUP_SECONDS="${BENCHMARK_STARTUP_SECONDS:-2}"

cd "$ROOT_DIR"

printf 'Run root: %s\n' "$RUN_ROOT"
printf 'Trials per target: %s\n' "$TRIALS"
printf 'Minimum valid trials per target: %s\n' "$MIN_VALID_TRIALS"

bash benchmark/capture-environment.sh "$RUN_ROOT/environment.txt"

run_target() {
    local target="$1"
    local label="$2"
    local out_dir="$RUN_ROOT/$label"
    local status=0
    mkdir -p "$out_dir"

    printf '\n== %s ==\n' "$label"

    set +e
    if [[ "$target" == "conveyor" ]]; then
        BENCHMARK_CSV_PREFIX="$out_dir/stats" \
        BENCHMARK_RESOURCE_REPORT="$out_dir/server-resources.csv" \
        BENCHMARK_LOCUST_RESOURCE_REPORT="$out_dir/locust-resources.csv" \
        BENCHMARK_SERVER_LOG="$out_dir/server.log" \
            bash benchmark/run-conveyor-with-resources.sh 2>&1 | tee "$out_dir/run.log"
        status="${PIPESTATUS[0]}"
    elif [[ "$target" == "reverb" ]]; then
        BENCHMARK_CSV_PREFIX="$out_dir/stats" \
        BENCHMARK_RESOURCE_REPORT="$out_dir/server-resources.csv" \
        BENCHMARK_LOCUST_RESOURCE_REPORT="$out_dir/locust-resources.csv" \
        BENCHMARK_SERVER_LOG="$out_dir/server.log" \
            bash benchmark/laravel-reverb/run-reverb-with-resources.sh 2>&1 | tee "$out_dir/run.log"
        status="${PIPESTATUS[0]}"
    else
        printf 'Unknown target: %s\n' "$target" >&2
        status=1
    fi
    set -e

    printf '%s\n' "$status" > "$out_dir/status.txt"
    if (( status != 0 )); then
        printf 'Target %s exited with status %s; continuing so the final report captures all planned trials.\n' "$label" "$status" >&2
    fi
}

run_target conveyor warmup-conveyor
run_target reverb warmup-reverb

for trial in $(seq 1 "$TRIALS"); do
    if (( trial % 2 == 1 )); then
        run_target conveyor "trial-${trial}-conveyor"
        run_target reverb "trial-${trial}-reverb"
    else
        run_target reverb "trial-${trial}-reverb"
        run_target conveyor "trial-${trial}-conveyor"
    fi
done

BENCHMARK_MIN_VALID_TRIALS="$MIN_VALID_TRIALS" \
    python3 benchmark/analyze-performance-results.py "$RUN_ROOT" > "$RUN_ROOT/report.md"
printf '\nReport: %s\n' "$RUN_ROOT/report.md"

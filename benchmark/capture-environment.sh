#!/usr/bin/env bash
set -euo pipefail

OUT="${1:-benchmark/results/environment.txt}"
mkdir -p "$(dirname "$OUT")"

run_section() {
    local title="$1"
    shift

    {
        printf '\n## %s\n\n' "$title"
        "$@" 2>&1 || true
    } >> "$OUT"
}

{
    printf '# Benchmark Environment\n'
    printf '\nCaptured at: %s\n' "$(date +%Y-%m-%dT%H:%M:%S%z)"
    printf 'Working directory: %s\n' "$(pwd)"
    printf 'Git commit: %s\n' "$(git rev-parse HEAD 2>/dev/null || printf unknown)"
    printf 'Git status:\n'
    git status --short 2>/dev/null || true
} > "$OUT"

run_section "OS" uname -a
run_section "CPU" sh -c 'lscpu 2>/dev/null || sysctl -a 2>/dev/null | grep -E "machdep.cpu|hw.ncpu"'
run_section "Memory" sh -c 'free -h 2>/dev/null || vm_stat 2>/dev/null'
run_section "PHP" php -v
run_section "PHP Modules" php -m
run_section "Composer Packages" composer show --locked
run_section "Python" python3 --version
run_section "Locust" sh -c 'if test -x benchmark/locust/.venv/bin/locust; then benchmark/locust/.venv/bin/locust --version; else locust --version; fi'
run_section "Laravel Reverb App Packages" sh -c 'cd benchmark/laravel-reverb/app && composer show --locked'
# Explicit allowlist: never persist credentials, tokens, app keys or secrets.
benchmark_environment() {
    local name
    for name in BENCHMARK_USERS BENCHMARK_PROCESSES BENCHMARK_SPAWN_RATE \
        BENCHMARK_RUN_TIME BENCHMARK_PAYLOAD_BYTES BENCHMARK_RESET_STATS \
        BENCHMARK_STOP_TIMEOUT BENCHMARK_STARTUP_SECONDS BENCHMARK_TRIALS \
        BENCHMARK_MIN_VALID_TRIALS BENCHMARK_CONVEYOR_WORKERS \
        BENCHMARK_CONVEYOR_TASK_WORKERS BENCHMARK_CONVEYOR_MEMORY_LIMIT; do
        if [[ -v "$name" ]]; then
            printf '%s=%s\n' "$name" "${!name}"
        fi
    done
}
run_section "Benchmark Environment Variables (safe allowlist)" benchmark_environment

#!/usr/bin/env python3
from __future__ import annotations

import csv
import os
import re
import statistics
import sys
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Trial:
    label: str
    target: str
    rest_rps: float
    rest_p50_ms: float
    rest_avg_ms: float
    rest_p95_ms: float
    rest_p99_ms: float
    fanout_p50_ms: float
    fanout_avg_ms: float
    fanout_p95_ms: float
    fanout_p99_ms: float
    failures: int
    exceptions: int
    server_max_cpu: float
    server_max_rss_kb: int
    locust_max_cpu: float
    locust_max_rss_kb: int
    valid: bool
    invalid_reason: str


def read_stats(path: Path) -> dict[str, dict[str, str]]:
    if not path.exists():
        return {}
    with path.open(newline="") as handle:
        return {row["Name"]: row for row in csv.DictReader(handle)}


def count_data_rows(path: Path) -> int:
    if not path.exists():
        return -1
    with path.open(newline="") as handle:
        return max(0, sum(1 for _ in csv.DictReader(handle)))


def resource_max(path: Path) -> tuple[float, int]:
    max_cpu = 0.0
    max_rss = 0
    if not path.exists():
        return max_cpu, max_rss
    with path.open(newline="") as handle:
        for row in csv.DictReader(handle):
            max_cpu = max(max_cpu, float(row.get("cpu_percent") or 0))
            max_rss = max(max_rss, int(float(row.get("rss_kb") or 0)))
    return max_cpu, max_rss


def row_float(row: dict[str, str], key: str) -> float:
    value = row.get(key) or "0"
    return float(value) if value != "N/A" else 0.0


def read_exit_status(path: Path) -> int | None:
    if not path.exists():
        return None

    value = path.read_text(errors="replace").strip()
    if not value:
        return None

    try:
        return int(value)
    except ValueError:
        return None


def analyze_trial(path: Path, require_exit_status: bool) -> Trial:
    label = path.name
    target = "conveyor" if label.endswith("conveyor") else "reverb"
    stats = read_stats(path / "stats_stats.csv")
    rest = stats.get("REST publish", {})
    fanout = stats.get("fanout receive", {})
    failure_rows = count_data_rows(path / "stats_failures.csv")
    exception_rows = count_data_rows(path / "stats_exceptions.csv")
    server_cpu, server_rss = resource_max(path / "server-resources.csv")
    locust_cpu, locust_rss = resource_max(path / "locust-resources.csv")

    reasons: list[str] = []
    server_log = path / "server.log"
    run_log = path / "run.log"
    exit_status = read_exit_status(path / "status.txt")
    if require_exit_status and exit_status is None:
        reasons.append("missing runner exit status")
    elif exit_status is not None and exit_status != 0:
        reasons.append(f"runner exited {exit_status}")
    if failure_rows != 0:
        reasons.append(f"{failure_rows} failure rows")
    if exception_rows != 0:
        reasons.append(f"{exception_rows} exception rows")
    if not rest:
        reasons.append("missing REST publish stats")
    if not fanout:
        reasons.append("missing WS fanout stats")
    if not (path / "stats_stats_history.csv").exists():
        reasons.append("missing stats_stats_history.csv")
    if not server_log.exists():
        reasons.append("missing server.log")
    elif re.search(
        r"\b(Fatal error|ERROR|Exception|Address already in use|abnormal exit|signal=11|A bug occurred)\b",
        server_log.read_text(errors="replace"),
    ):
        reasons.append("server log contains error")
    if not run_log.exists():
        reasons.append("missing run.log")
    elif "CPU usage above 90%" in run_log.read_text(errors="replace"):
        reasons.append("locust reported cpu saturation")
    # ps CPU is summed across the master and all worker processes; 95% of
    # one core is not saturation for a six-worker generator. Locust emits its
    # own per-worker saturation warning, checked above.
    for filename in ("server-resources.csv", "locust-resources.csv"):
        if count_data_rows(path / filename) <= 0:
            reasons.append(f"missing or empty {filename}")
    for name, row in (("REST publish", rest), ("WS fanout", fanout)):
        if row and row_float(row, "Request Count") <= 0:
            reasons.append(f"no {name} requests")

    return Trial(
        label=label,
        target=target,
        rest_rps=row_float(rest, "Requests/s"),
        rest_p50_ms=row_float(rest, "50%"),
        rest_avg_ms=row_float(rest, "Average Response Time"),
        rest_p95_ms=row_float(rest, "95%"),
        rest_p99_ms=row_float(rest, "99%"),
        fanout_p50_ms=row_float(fanout, "50%"),
        fanout_avg_ms=row_float(fanout, "Average Response Time"),
        fanout_p95_ms=row_float(fanout, "95%"),
        fanout_p99_ms=row_float(fanout, "99%"),
        failures=max(0, failure_rows),
        exceptions=max(0, exception_rows),
        server_max_cpu=server_cpu,
        server_max_rss_kb=server_rss,
        locust_max_cpu=locust_cpu,
        locust_max_rss_kb=locust_rss,
        valid=not reasons,
        invalid_reason=", ".join(reasons),
    )


def median(values: list[float]) -> float:
    return statistics.median(values) if values else 0.0


def minimum_valid_trials() -> int:
    value = os.getenv("BENCHMARK_MIN_VALID_TRIALS", "5")
    try:
        return int(value)
    except ValueError:
        return 5


def emit_summary(target: str, trials: list[Trial]) -> None:
    valid = [trial for trial in trials if trial.target == target and trial.valid]
    print(f"### {target.title()}")
    print()
    print(f"Valid trials: {len(valid)}")
    if not valid:
        print()
        return
    print()
    print("| Metric | Median | Min | Max |")
    print("| --- | ---: | ---: | ---: |")
    metrics = [
        ("REST publish rps", [trial.rest_rps for trial in valid]),
        ("REST p50 ms", [trial.rest_p50_ms for trial in valid]),
        ("REST avg ms", [trial.rest_avg_ms for trial in valid]),
        ("REST p95 ms", [trial.rest_p95_ms for trial in valid]),
        ("REST p99 ms", [trial.rest_p99_ms for trial in valid]),
        ("WS fanout p50 ms", [trial.fanout_p50_ms for trial in valid]),
        ("WS fanout avg ms", [trial.fanout_avg_ms for trial in valid]),
        ("WS fanout p95 ms", [trial.fanout_p95_ms for trial in valid]),
        ("WS fanout p99 ms", [trial.fanout_p99_ms for trial in valid]),
        ("Server max CPU %", [trial.server_max_cpu for trial in valid]),
        ("Locust max CPU %", [trial.locust_max_cpu for trial in valid]),
    ]
    for name, values in metrics:
        print(f"| {name} | {median(values):.2f} | {min(values):.2f} | {max(values):.2f} |")
    print()


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: analyze-performance-results.py <run-root>", file=sys.stderr)
        return 2

    root = Path(sys.argv[1])
    min_valid_trials = minimum_valid_trials()
    trial_dirs = sorted(path for path in root.iterdir() if path.is_dir() and path.name.startswith("trial-"))
    require_exit_status = any((path / "status.txt").exists() for path in trial_dirs)
    trials = [analyze_trial(path, require_exit_status) for path in trial_dirs]

    print("# Benchmark Comparison Run Report")
    print()
    print(f"Run root: `{root}`")
    print(f"Minimum valid trials per target: `{min_valid_trials}`")
    print()
    print("## Validity")
    print()
    print("| Trial | Target | Valid | Reason |")
    print("| --- | --- | --- | --- |")
    for trial in trials:
        reason = trial.invalid_reason or ""
        print(f"| {trial.label} | {trial.target} | {'yes' if trial.valid else 'no'} | {reason} |")
    print()
    print("## Aggregate Results")
    print()
    emit_summary("conveyor", trials)
    emit_summary("reverb", trials)

    conveyor = [trial.rest_rps for trial in trials if trial.target == "conveyor" and trial.valid]
    reverb = [trial.rest_rps for trial in trials if trial.target == "reverb" and trial.valid]
    if len(conveyor) >= min_valid_trials and len(reverb) >= min_valid_trials:
        ratio = median(conveyor) / median(reverb)
        print("## Claim Draft")
        print()
        print(
            "Across independently filtered valid trials in this run, Socket Conveyor reached "
            f"median REST publish throughput of {median(conveyor):.2f} requests/sec "
            f"versus Laravel Reverb's {median(reverb):.2f} requests/sec "
            f"({ratio:.2f}x), with zero recorded Locust failures or exceptions."
        )
        print()
    else:
        print("## Claim Draft")
        print()
        print(
            "No comparison claim is available from this run: "
            f"{len(conveyor)} valid Conveyor trial(s) and {len(reverb)} valid Reverb trial(s) "
            f"were collected, but at least {min_valid_trials} valid trials per target are required."
        )
        print()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

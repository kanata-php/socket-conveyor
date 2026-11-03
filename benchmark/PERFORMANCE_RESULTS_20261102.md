# Performance results — November 2, 2026

The isolated local comparison completed five valid trials per target after one
warmup per target: 100 users, six Locust worker processes, two minutes per trial,
256-byte payloads, spawn rate 10, and counters reset after spawning. Each of the
12 target invocations exited zero; no Locust failures, exceptions, per-worker
CPU saturation warnings, server log errors, startup collisions, or surviving
process shutdown errors were recorded.

Median REST publish throughput was **887.57 requests/sec for Conveyor** and
**649.22 requests/sec for Reverb**, a ratio of **1.37x**. Conveyor trial throughput
ranged from 602.40–1074.61 requests/sec; Reverb ranged from 494.83–895.55. This
variation and the shared host limit the conclusion to this measured workload.
This is not an internet, multi-node, authorization, or general capacity claim.

| Metric (median across trials) | Conveyor | Reverb |
| --- | ---: | ---: |
| REST publish p50 | 74 ms | 88 ms |
| REST publish p95 | 150 ms | 270 ms |
| REST publish p99 | 170 ms | 470 ms |
| WS fanout receive average | 0.24 ms | 0.22 ms |
| WS fanout receive p99 | 0 ms | 1 ms |

Locust percentile rounding produces the reported zero-millisecond fanout values;
they do not establish zero transport latency. The workload measures delivery of
the matching frame to the publishing client, rather than delivery to every
subscriber. The raw CSVs retain the recorded measurements.

## Runtime and provenance

Both servers and the load generator ran in one isolated Docker bridge network
namespace, without published ports or host networking, using PHP 8.4.27,
OpenSwoole 26.2.0, and Locust 2.44.1. Reverb used the existing locked Laravel app;
its dependencies were installed without Composer scripts or plugins. Existing
configuration was mounted read-only; no secret was generated or changed.

The benchmark worktree was based on Conveyor `0d7d924`, with the validated
`src/Traits/HasHandlers.php` from runtime commit
`4ea5ffb387249e020a64bef672e7870e40b90ef7` mounted read-only over its original
source. That file's SHA-256 was
`8cdd7bfc2366e5a8e91bf4df28c57b1861868746b0b7f2b79d509b9bf76da965`.
All other PHP source remained at the benchmark worktree base.

Runner scripts started at benchmark commit `408e7db`. During the run, the
cleanup helpers were hardened to retain reparented descendants and return a
failure for surviving processes; this changes shutdown handling, not the
workload. Subsequent target invocations loaded the new helpers. The saved
`during-run-script.patch` documents that change. Complete logs and resource
samples exist for all trials, and no collision or shutdown survival was
recorded, so no trial was invalidated by this change. The analyzer also began
requiring `run.log`, which all trials possess, and clarified that each target's
valid trials are filtered independently; all ten measured trials passed.

The first attempted run (`20261102-paired`) was stopped during warmup because
Alpine's BusyBox date rejected a GNU-specific timestamp option. Its artifacts
are retained separately and are excluded from this comparison; the completed
run uses portable timestamps.

## Artifacts

Local artifact directory:
`/home/savior/Code/Studies/socket-conveyor/benchmark/results/runs/20261102-paired-v2/`

It contains `environment.txt`, `execution.log`, `report.md`,
`source-provenance.txt`, `during-run-script.patch`, both warmup directories, and
all ten trial directories, each with Locust CSVs, server/load-generator resource
samples, runner/server logs and exit status. Generated artifacts are ignored by
Git; retain this directory when cleaning up the worktree.

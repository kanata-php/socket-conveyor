# Performance Measurement Methodology

This document describes how Socket Conveyor benchmark results are measured and
calculated for the included Pusher-compatible workload.

## Comparison

The benchmark compares Socket Conveyor with Laravel Reverb on the same host
using the same Pusher-compatible workload:

- WebSocket clients connect with the Pusher protocol.
- Each client subscribes to the same public channel.
- Locust publishes signed REST events to `/apps/{app_id}/events`.
- Each client waits for the matching `benchmark.message` frame over its
  WebSocket connection.

The comparison is limited to this local workload. It does not measure internet
latency, multi-node fanout, private-channel authorization, presence channels, or
all possible Laravel Reverb deployment configurations.

## Fixed Inputs

Use the same benchmark settings for both targets:

- `BENCHMARK_USERS`
- `BENCHMARK_SPAWN_RATE`
- `BENCHMARK_RUN_TIME`
- `BENCHMARK_PAYLOAD_BYTES`
- `BENCHMARK_CHANNEL`
- app id, app key, and app secret
- host machine
- PHP memory limit
- server worker settings, or a documented reason they differ

`BENCHMARK_RESET_STATS=true` is used for headline throughput and latency. This
resets Locust's counters after all users have spawned, so the final totals
describe the steady-state interval rather than ramp-up.

## Required Metadata

Each result set records the environment beside the raw CSV and log artifacts:

- OS and kernel
- CPU model and logical CPU count
- memory
- PHP version
- Composer package versions
- OpenSwoole version
- Laravel version
- Reverb version
- Python version
- Locust version
- git commit SHA
- benchmark environment variables from an explicit safe allowlist (credentials excluded)

The paired runner defaults to 100 users and six Locust worker processes, with
one two-minute warmup per target followed by five two-minute trials per target.
Locust CPU is the sum across master and workers; saturation is identified from
Locust's per-process CPU warnings, not a one-core threshold on that sum.

## Result Layout

Each run is stored in a unique directory:

```text
benchmark/results/runs/<timestamp>/
  environment.txt
  report.md
  warmup-conveyor/
  warmup-reverb/
  trial-1-conveyor/
  trial-1-reverb/
  trial-2-reverb/
  trial-2-conveyor/
```

Each trial directory contains the raw Locust CSVs, server resource samples,
Locust resource samples, server log, run log, and runner exit status.

## Valid Trial Rules

A trial is excluded from aggregate results if it has any of these conditions:

- Locust failures
- Locust exceptions
- nonzero target runner exit status
- server log errors
- server crash or restart
- missing result files
- load generator CPU saturation

Aggregate comparisons require at least five valid trials per target. Median
values are calculated across valid trials only.

## Reported Metrics

Headline metrics:

- `REST publish` requests per second
- `REST publish` p50, p95, and p99 latency
- `WS fanout receive` p50, p95, and p99 latency
- failure rate

Diagnostic metrics:

- `WS connect`
- `WS subscribe`
- server CPU
- server memory
- load generator CPU
- load generator memory
- process count

The report shows median, minimum, and maximum values across valid trials. A
single best run is not used as the comparison result.

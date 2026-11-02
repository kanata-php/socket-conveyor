# Performance Results - 2026-07-09

Status: **no valid Socket Conveyor vs Laravel Reverb comparison result**

This run measured the included local Pusher-compatible Locust workload against
Socket Conveyor and Laravel Reverb on the same machine. The run did not produce
an aggregate comparison because there were not enough valid trials for both
targets.

## Artifacts

Raw artifacts are under:

```text
benchmark/results/runs/20260709T211032Z/
```

The generated report is:

```text
benchmark/results/runs/20260709T211032Z/report.md
```

## Configuration

- captured at: `2026-07-09T16:10:32-05:00`
- git commit at capture: `0d7d9240fc6a2a2cbb34b845045036dcd98ce069`
- users: `100`
- spawn rate: `10`
- run time: `2m`
- Locust processes: `6`
- reset stats: `true`
- stop timeout: `2`
- target ports: Conveyor `127.0.0.1:8990`, Reverb `127.0.0.1:8991`
- machine: `13th Gen Intel(R) Core(TM) i5-1334U`, 12 logical CPUs

## Workload

Each Locust user opened one WebSocket connection, subscribed to a public Pusher
channel, published signed REST events to `/apps/{app_id}/events`, and waited for
the matching `benchmark.message` frame to arrive over the WebSocket connection.

## Valid Trial Count

| Target | Valid measured trials | Required valid trials |
| --- | ---: | ---: |
| Socket Conveyor | 0 | 5 |
| Laravel Reverb | 2 | 5 |

Because Socket Conveyor had zero valid measured trials and Laravel Reverb had
only two valid measured trials, this run has no aggregate comparison result.

## Trial Validity

| Trial | Target | Included | Reason excluded |
| --- | --- | --- | --- |
| `trial-1-conveyor` | Socket Conveyor | no | server log contained an OpenSwoole worker failure |
| `trial-1-reverb` | Laravel Reverb | yes | |
| `trial-2-conveyor` | Socket Conveyor | no | server log contained an OpenSwoole worker failure |
| `trial-2-reverb` | Laravel Reverb | yes | |
| `trial-3-conveyor` | Socket Conveyor | no | Locust failure row, missing REST/fanout stats, server failed to bind port |

## Calculated Values

The only aggregate values available from valid trials are Laravel Reverb
diagnostics. They are not a Conveyor-vs-Reverb comparison.

| Metric | Laravel Reverb median | Min | Max |
| --- | ---: | ---: | ---: |
| REST publish requests/sec | 1049.24 | 1044.52 | 1053.97 |
| REST average latency ms | 64.18 | 63.83 | 64.54 |
| REST p95 latency ms | 105.00 | 100.00 | 110.00 |
| REST p99 latency ms | 140.00 | 140.00 | 140.00 |
| WS fanout average latency ms | 0.15 | 0.15 | 0.15 |
| WS fanout p95 latency ms | 0.00 | 0.00 | 0.00 |
| WS fanout p99 latency ms | 0.00 | 0.00 | 0.00 |
| Server max CPU % | 92.25 | 92.10 | 92.40 |
| Locust max CPU % | 54.45 | 42.30 | 66.60 |

## Calculation Method

The analyzer read each trial's Locust `stats_stats.csv`, `stats_failures.csv`,
`stats_exceptions.csv`, `stats_stats_history.csv`, server log, server resource
CSV, and Locust resource CSV. A trial was included only when it had complete
stats, zero Locust failures, zero Locust exceptions, no server error pattern,
and no load-generator CPU saturation.

For each reported metric, the analyzer calculated the median, minimum, and
maximum across included trials for that target.

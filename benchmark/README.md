# Socket Conveyor Benchmarks

This directory contains the Locust benchmark for Socket Conveyor's
Pusher/Reverb-compatible surface. Locust is the primary benchmark path: each
simulated user opens a WebSocket connection at `/app/{key}`, subscribes to a
public channel, publishes signed REST events to `/apps/{app_id}/events`, and
waits for the matching `benchmark.message` frame over the WebSocket connection.

Locust reports live throughput, latency percentiles, failures, and CSV exports.
Interpret results in the context of the host, PHP build, OpenSwoole settings,
Laravel/Reverb setup, worker count, and load settings used for that run.

## Quick Start

From the repository root, install the Python benchmark dependencies once:

```bash
cd benchmark/locust
python3 -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
mkdir -p ../results
cd ../..
```

Start Socket Conveyor in Pusher/Reverb-compatible mode:

```bash
php benchmark/server-conveyor.php
```

In a second terminal, run Locust:

```bash
cd benchmark/locust
. .venv/bin/activate
locust -f locustfile.py --host=http://127.0.0.1:8990
```

Open `http://localhost:8089`, enter a user count and spawn rate, then start the
run.

For a non-interactive CSV run:

```bash
cd benchmark/locust
. .venv/bin/activate
locust -f locustfile.py \
  --headless \
  --users 100 \
  --spawn-rate 10 \
  --run-time 2m \
  --host=http://127.0.0.1:8990 \
  --csv ../results/conveyor
```

## Install Locust

```bash
cd benchmark/locust
python3 -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
mkdir -p ../results
```

## Benchmark Conveyor

Start the included Conveyor benchmark target from the repository root:

Terminal 1:

```bash
php benchmark/server-conveyor.php
```

Run Locust against that target:

Terminal 2:

```bash
cd benchmark/locust
. .venv/bin/activate
locust -f locustfile.py --host=http://127.0.0.1:8990
```

Open the Locust UI at `http://localhost:8089`, choose the number of users and
spawn rate, then start the run.

For repeatable runs, prefer headless mode with CSV output:

```bash
cd benchmark/locust
. .venv/bin/activate
locust -f locustfile.py \
  --headless \
  --users 100 \
  --spawn-rate 10 \
  --run-time 2m \
  --host=http://127.0.0.1:8990 \
  --csv ../results/conveyor
```

The included Conveyor target defaults to `127.0.0.1:8990` with app id
`local-app`, key `local-key`, and secret `local-secret`.

## Compare With Reverb

Use the included Laravel 13 + Reverb fixture when comparing against Reverb. It
creates an ignored Laravel app in `benchmark/laravel-reverb/app`, installs
Reverb, and configures matching benchmark credentials.

Set it up once:

```bash
bash benchmark/laravel-reverb/setup.sh
```

Terminal 1:

```bash
bash benchmark/laravel-reverb/start-reverb.sh
```

Terminal 2:

```bash
bash benchmark/laravel-reverb/run-locust.sh
```

Use the same users, spawn rate, run time, payload size, and worker/server
settings for Conveyor and Reverb when comparing result files. See
`benchmark/laravel-reverb/README.md` for fixture details and override
environment variables.

### Fair comparison window

Both runners pass `--reset-stats` and a bounded `--stop-timeout` so the two
servers are measured over the **same window**:

- `--reset-stats` zeroes the counters once every user has spawned, so the
  ramp-up period (≈ `users / spawn-rate` seconds) is excluded. Because both
  runs use identical `users`/`spawn-rate`, the measured steady-state interval
  is the same length for each server.
- `--stop-timeout` caps how long Locust waits for in-flight tasks after
  `--run-time` elapses. Without it, a slower server drains its request backlog
  for much longer, keeping the run alive and inflating its total request count
  long after the load window closed.

Together these stop a slow side from racking up "extra" requests during ramp-up
and drain, so **total request counts become directly comparable** rather than a
function of wall-clock run length. Prefer comparing `Requests/s` and the latency
percentiles regardless — those are duration-independent.

## Target Environment

Locust uses `--host` for the target base URL. The benchmark-specific settings
come from environment variables:

- `BENCHMARK_APP_ID`: Pusher/Reverb app id. Falls back to `PUSHER_APP_ID`,
  then `local-app`.
- `BENCHMARK_APP_KEY`: Pusher/Reverb app key. Falls back to `PUSHER_APP_KEY`,
  then `local-key`.
- `BENCHMARK_APP_SECRET`: Pusher/Reverb app secret. Falls back to
  `PUSHER_APP_SECRET`, then `local-secret`.
- `BENCHMARK_CHANNEL`: public channel name. Default: `benchmark-channel`.
- `BENCHMARK_PAYLOAD_BYTES`: bytes in each event data payload. Default: `256`.
- `BENCHMARK_RECEIVE_TIMEOUT`: seconds to wait for each expected WebSocket
  delivery. Default: `10`.
- `BENCHMARK_WAIT_MIN_SECONDS`: minimum wait between Locust tasks. Default:
  `0.01`.
- `BENCHMARK_WAIT_MAX_SECONDS`: maximum wait between Locust tasks. Default:
  `0.05`.
- `BENCHMARK_STOP_TIMEOUT`: seconds Locust waits for in-flight tasks to finish
  after `--run-time` elapses. Caps the post-run drain so a slow server cannot
  extend its measured window. Default: `2`.

The included Conveyor benchmark server also accepts:

- `BENCHMARK_CONVEYOR_HOST`: host to bind. Falls back to `CONVEYOR_HOST`, then
  `127.0.0.1`.
- `BENCHMARK_CONVEYOR_PORT`: port to bind. Falls back to `CONVEYOR_PORT`, then
  `8990`.
- `BENCHMARK_CONVEYOR_APP_ID`: Conveyor app id. Falls back to `PUSHER_APP_ID`,
  then `local-app`.
- `BENCHMARK_CONVEYOR_APP_KEY`: Conveyor app key. Falls back to
  `PUSHER_APP_KEY`, then `local-key`.
- `BENCHMARK_CONVEYOR_APP_SECRET`: Conveyor app secret. Falls back to
  `PUSHER_APP_SECRET`, then `local-secret`.
- `BENCHMARK_CONVEYOR_WORKERS`: OpenSwoole `worker_num`. Default: `1`.
- `BENCHMARK_CONVEYOR_TASK_WORKERS`: OpenSwoole `task_worker_num`. Default:
  `1`.

When comparing Conveyor and Reverb, keep the Locust settings, app credentials,
channel, payload size, and server worker settings equivalent between runs.

## Mechanical Checks

```bash
python3 -m py_compile benchmark/locust/locustfile.py
python3 benchmark/locust/locustfile.py --self-test
php -l benchmark/server-conveyor.php
vendor/bin/phpunit --testsuite All
```

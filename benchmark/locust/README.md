# Locust Benchmark

This is the primary benchmark workflow for Socket Conveyor and compatible
Pusher/Reverb targets. Run commands in this file from `benchmark/locust` unless
the command explicitly says to use the repository root or a Laravel app
directory.

Each Locust user keeps one WebSocket connection open, subscribes to one public
channel, publishes a signed REST event, and waits for the event to arrive back
over WebSocket. The Locust file records:

- `WS connect`: WebSocket upgrade plus `pusher:connection_established`.
- `WS subscribe`: public-channel subscription acknowledgement.
- `REST publish`: signed publish request to `/apps/{app_id}/events`.
- `WS fanout receive`: time to receive the published `benchmark.message`.

Locust reports throughput, latency percentiles, failures, and optional CSV
files. These docs describe how to run the benchmark; they do not include or
imply benchmark results.

## Install

Create the Python environment and a result directory:

```bash
python3 -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
mkdir -p ../results
```

Reactivate the virtual environment before each Locust run:

```bash
. .venv/bin/activate
```

## Conveyor Target

Start the included Conveyor benchmark server from the repository root:

```bash
php benchmark/server-conveyor.php
```

By default it listens on `127.0.0.1:8990` and serves app id `local-app`, key
`local-key`, and secret `local-secret`.

Run Locust with the web UI:

```bash
locust -f locustfile.py --host=http://127.0.0.1:8990
```

Open `http://localhost:8089`, choose user count and spawn rate, then start the
run.

Run Locust headless with CSV output:

```bash
BENCHMARK_APP_ID=local-app \
BENCHMARK_APP_KEY=local-key \
BENCHMARK_APP_SECRET=local-secret \
locust -f locustfile.py \
  --headless \
  --users 100 \
  --spawn-rate 10 \
  --run-time 2m \
  --host=http://127.0.0.1:8990 \
  --csv ../results/conveyor
```

Locust writes CSV files with the chosen prefix, such as
`../results/conveyor_stats.csv`, `../results/conveyor_failures.csv`, and
`../results/conveyor_exceptions.csv`.

## Reverb Target

Use the included Laravel 13 + Reverb fixture when comparing against Reverb. Run
these commands from the repository root.

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

Use the same users, spawn rate, run time, payload size, channel, app
credentials, and comparable server worker settings when comparing Conveyor and
Reverb CSV files. See `../laravel-reverb/README.md` for fixture details and
override environment variables.

## Environment

Locust uses `--host` as the target base URL. It converts `http://` targets to
`ws://` for WebSocket connections and `https://` targets to `wss://`.

Locust benchmark settings:

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

Included Conveyor server settings:

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

## Mechanical Checks

Run these from the repository root before handing off benchmark changes:

```bash
python3 -m py_compile benchmark/locust/locustfile.py
python3 benchmark/locust/locustfile.py --self-test
php -l benchmark/server-conveyor.php
vendor/bin/phpunit --testsuite All
```

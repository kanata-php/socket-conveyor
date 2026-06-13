# Laravel 13 Reverb Benchmark Context

This directory provisions and runs a local Laravel 13 + Reverb target for the
Locust benchmark in `../locust`. The generated Laravel app lives at
`benchmark/laravel-reverb/app` by default and is ignored by git.

The benchmark assumes the same app credentials as the included Conveyor target:

- app id: `local-app`
- app key: `local-key`
- app secret: `local-secret`
- Reverb bind address: `127.0.0.1:8991`

## Create the Laravel App

Run the setup script:

```bash
bash benchmark/laravel-reverb/setup.sh
```

The script creates a Laravel 13 app, requires `laravel/reverb`, publishes the
Reverb config, and writes the benchmark settings into the generated app's
`.env`.

For local public-channel benchmarking, no application routes, controllers, or
events are required. Locust publishes directly to Reverb's Pusher-compatible
REST API at `/apps/{app_id}/events` and receives the event over `/app/{key}`.

## Run Reverb

From the repository root:

```bash
bash benchmark/laravel-reverb/start-reverb.sh
```

Add `--debug` only for troubleshooting. It adds logging overhead and should not
be used for comparable benchmark runs.

## Run Locust Against Reverb

From the repository root:

```bash
bash benchmark/laravel-reverb/run-locust.sh
```

Keep `--users`, `--spawn-rate`, `--run-time`, `BENCHMARK_PAYLOAD_BYTES`, and
`BENCHMARK_CHANNEL` identical to the Conveyor run when comparing CSV files.
The run script accepts these overrides:

- `BENCHMARK_USERS`: Locust user count. Default: `100`.
- `BENCHMARK_SPAWN_RATE`: Locust spawn rate. Default: `10`.
- `BENCHMARK_RUN_TIME`: Locust run time. Default: `2m`.
- `BENCHMARK_CSV_PREFIX`: CSV output prefix. Default:
  `../results/reverb-laravel13`.

## Sanity Check

Before a benchmark run, confirm the signing helper is using the expected
credentials:

```bash
BENCHMARK_APP_ID=local-app \
BENCHMARK_APP_KEY=local-key \
BENCHMARK_APP_SECRET=local-secret \
python3 benchmark/locust/locustfile.py --self-test
```

Then start with a small Locust run:

```bash
BENCHMARK_USERS=1 \
BENCHMARK_SPAWN_RATE=1 \
BENCHMARK_RUN_TIME=10s \
bash benchmark/laravel-reverb/run-locust.sh
```

If this fails with `401`, `403`, or publish timeouts, check the Laravel app's
`.env` and run `php artisan config:clear` before restarting Reverb.

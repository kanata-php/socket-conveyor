#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${BENCHMARK_REVERB_APP_DIR:-$ROOT_DIR/app}"

if [[ ! -d "$APP_DIR" ]]; then
    composer create-project laravel/laravel:"^13.0" "$APP_DIR" --no-interaction
fi

cd "$APP_DIR"

if [[ ! -f artisan ]]; then
    echo "Expected Laravel app at $APP_DIR, but artisan was not found." >&2
    exit 1
fi

if ! composer show laravel/reverb >/dev/null 2>&1; then
    composer require laravel/reverb --no-interaction
fi

php artisan vendor:publish --tag=reverb-config --force --no-interaction

php -r '
$path = ".env";
$values = [
    "APP_NAME" => "\"Socket Conveyor Reverb Benchmark\"",
    "APP_ENV" => "local",
    "APP_DEBUG" => "false",
    "APP_URL" => "http://127.0.0.1:8000",
    "BROADCAST_CONNECTION" => "reverb",
    "REVERB_APP_ID" => "local-app",
    "REVERB_APP_KEY" => "local-key",
    "REVERB_APP_SECRET" => "local-secret",
    "REVERB_HOST" => "127.0.0.1",
    "REVERB_PORT" => "8991",
    "REVERB_SCHEME" => "http",
    "REVERB_SERVER_HOST" => "127.0.0.1",
    "REVERB_SERVER_PORT" => "8991",
    "VITE_REVERB_APP_KEY" => "\"\${REVERB_APP_KEY}\"",
    "VITE_REVERB_HOST" => "\"\${REVERB_HOST}\"",
    "VITE_REVERB_PORT" => "\"\${REVERB_PORT}\"",
    "VITE_REVERB_SCHEME" => "\"\${REVERB_SCHEME}\"",
];

$env = file_exists($path) ? file_get_contents($path) : "";
foreach ($values as $key => $value) {
    $line = $key . "=" . $value;
    if (preg_match("/^" . preg_quote($key, "/") . "=.*/m", $env)) {
        $env = preg_replace("/^" . preg_quote($key, "/") . "=.*/m", $line, $env);
    } else {
        $env = rtrim($env) . PHP_EOL . $line . PHP_EOL;
    }
}
file_put_contents($path, $env);
'

php artisan config:clear
php artisan config:cache

cat <<EOF
Laravel Reverb benchmark app is ready:
  $APP_DIR

Start Reverb with:
  bash $ROOT_DIR/start-reverb.sh

Run Locust against it with:
  bash $ROOT_DIR/run-locust.sh
EOF

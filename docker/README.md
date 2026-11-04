# Run Conveyor with Docker

The root Dockerfile builds a standalone Pusher-compatible Conveyor server with
PHP 8.5.12 and OpenSwoole 26.2.0, including OpenSSL support; no host PHP or
Composer installation is needed. It runs as UID/GID 10001 and includes only
production Composer dependencies, source, the server launcher, and the license.
The existing `runtime.Dockerfile` remains the development/benchmark image.

## Run the published image

The project-maintained image is available on
[Docker Hub](https://hub.docker.com/r/lotharthesavior/socket-conveyor) for
Linux amd64 and arm64, including Docker Desktop on Intel and Apple Silicon.

With your existing `PUSHER_APP_ID`, `PUSHER_APP_KEY`, and `PUSHER_APP_SECRET`
exported in the shell, start it in one command:

```sh
docker run --rm --init -p 127.0.0.1:8080:8080 -e PUSHER_APP_ID -e PUSHER_APP_KEY -e PUSHER_APP_SECRET lotharthesavior/socket-conveyor:2.6.0
```

Tags follow Semantic Versioning: `2.6.0` identifies this release, `2.6` tracks
its minor series, `2` tracks major version 2, and `latest` tracks the latest
stable release. Use the full version tag for a specific release.

## Build locally

To build from the repository root instead:

```sh
docker build --pull -t socket-conveyor:local .
```

With your existing `PUSHER_APP_ID`, `PUSHER_APP_KEY`, and `PUSHER_APP_SECRET`
exported in the shell, start it in one command:

```sh
docker run --rm --init -p 127.0.0.1:8080:8080 -e PUSHER_APP_ID -e PUSHER_APP_KEY -e PUSHER_APP_SECRET socket-conveyor:local
```

Alternatively, pass an existing dedicated environment file using `--env-file`.
The image requires credentials and never generates or logs them. Match these
values to Laravel's `REVERB_APP_ID`, `REVERB_APP_KEY`, and `REVERB_APP_SECRET`,
then set `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, and `REVERB_SCHEME=http`
when Laravel runs on the host. See the [Laravel Echo guide](../docs/laravel-echo-reverb-compatibility.md)
for browser setup and channel authorization. Containers on a shared Docker
network should use the Conveyor service/container name instead of localhost.

`CONVEYOR_HOST` defaults to `0.0.0.0` inside the container and
`CONVEYOR_PORT` to `8080`; ports must be in the range 1024–65535. If changing
the internal port, adjust the published port too. The built-in health check
tests TCP liveness; `docker/smoke.py` verifies actual WebSocket delivery.
The default launcher uses one event worker and one task worker, supports
presence and client messages, and stays in the foreground for Docker signals.

For your own OpenSwoole program, mount its script read-only and override the
command, for example `socket-conveyor:local php /app/server.php`; the Conveyor
autoload file is `/opt/conveyor/vendor/autoload.php`.

## Updates and verification

The PHP base is pinned by version and multi-platform digest; Alpine packages
are upgraded during the build, and a Composer audit rejects known advisories
in the locked production dependencies. Rebuild with `--pull --no-cache` for OS package
updates and review newer stable PHP/OpenSwoole versions regularly, updating
their pins and rerunning checks. A successful build does not guarantee absence
of vulnerabilities in the OS, extension, or application dependencies.
Terminate public TLS at a suitably configured reverse proxy.

```sh
python3 docker/smoke.py socket-conveyor:local
```

The image is built from source and checked in CI. Its OCI image labels record
the release version and source revision. The build context allowlist excludes `.env`
files, local dependencies, Git history, and benchmark artifacts.

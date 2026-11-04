# Security regression verification

The PHPUnit regressions cover app isolation, fd reuse, transaction rollback at real physical storage failure, exact concurrent quotas, configuration rejection, monotonic rate boundaries, bounded random-ID collision retries, entropy failure, and killed-worker lease recovery.

Build the development runtime with the existing `docker/runtime.Dockerfile`, and install the locked dependencies using the repository development setup before running checks; the commands below reuse those dependencies read-only. All servers run in disposable Docker namespaces; native feature-test ports never publish to the host.

```sh
docker run --rm --network none --memory 2g --cpus 1 --pids-limit 128 \
  --read-only --tmpfs /tmp:rw,nosuid,size=32m \
  -v "$PWD:/workspace:ro" -w /workspace socket-conveyor-runtime:latest \
  php vendor/bin/phpunit --do-not-cache-result --testsuite Unit

docker run --rm --network none --memory 4g --cpus 1 --pids-limit 128 \
  --read-only --tmpfs /tmp:rw,nosuid,size=32m \
  -v "$PWD:/workspace:ro" -w /workspace socket-conveyor-runtime:latest \
  php vendor/bin/phpunit --do-not-cache-result --testsuite Feature

python3 tests/Security/wire-regressions.py \
  --source "$PWD" --vendor "$PWD/vendor" --output /absolute/disposable/results
```

The wire runner snapshots source under its output directory, mounts vendor read-only, uses loopback-only ephemeral Docker ports and fixed existing public fixtures from `docker/smoke.py` and `examples/pusher-real/run-conveyor.php`, records image/container identities, and removes only its own containers. It never loads `.env` or account credentials. Run subsets with `--only replay,worker_death`.

Wire cases exercise the recorded malformed 201-byte channel and scalar payload threats, worker PID survival, 164-byte channels and 2048-byte presence data, two-app public/private/presence WS+REST isolation, private and presence saved-signature reconnect/restart replay, exact raw presence HMAC bytes, client/frame/body/rate limits, per-socket/app/global quotas, presence duplicate users, custom adapter partial-write rollback, fragmented oversized input, slow-consumer eviction, and task-worker death during a presence write.

The slow-consumer regression sets the receive window before connecting, requires a real `output buffer overflow` within at most 1600 10,000-byte publishes, verifies eviction and every healthy-peer message, then checks another 200 messages over at least five seconds with progress checkpoints, a final control ping and unchanged worker identities. The HTTP read deadline remains four seconds and each successful publication must finish within three seconds; a finite train without actual saturation fails instead of claiming eviction coverage. Message identities detect loss and duplication without assuming delivery order across different event workers.

Memory budgets describe the tests: native feature tests run in separate PHPUnit processes so preallocated OpenSwoole shared tables and forked callback fixtures are released after every test. Before this isolation, the combined suite exceeded 4 GiB. The combined All suite uses a 4 GiB container; isolated wire services use a 512 MiB limit. Native and third-party feature diagnostics remain logged under /tmp/conveyor-feature-diagnostics.log, away from PHPUnit child-process stderr IPC. This is not a production throughput or capacity benchmark. The deterministic source seams load only in separate PHPUnit processes and never alter production code.

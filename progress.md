# Docker image — November 3, 2026

- Working directly on `master` at `17aae61`; only master and the primary worktree remain.
- Prior work was merged as PR #3, released as 2.5.1, and passed hosted Tests on the merge commit.
- Original dirty changes are preserved in two Git stashes and bundles under `/home/savior/.local/state/socket-conveyor-backups/20261103-cleanup/`; raw benchmarks remain under `benchmark/results/runs/`.
- Current task: prepare a ready-to-run Conveyor Dockerfile with current stable PHP/OpenSwoole; no container registry publication requested.
- Added pinned PHP 8.5.12/OpenSwoole 26.2.0 image, non-root server launcher, allowlisted build context, Docker usage guide, runtime smoke, and container CI workflow.
- Local image built successfully: `/tmp/socket-conveyor-image-build-final.log`; PHP 8.5.12/OpenSwoole 26.2.0 with OpenSSL, approximately 65 MB (Docker image size).
- `python3 docker/smoke.py socket-conveyor:local` passed: real signed REST-to-WebSocket delivery, default/custom ports, credential/port validation, non-root read-only execution with dropped capabilities, health check and graceful shutdown; `/tmp/socket-conveyor-image-smoke.log`.
- Full suite on the PHP 8.5 image passed 69 tests/267 assertions; `/tmp/socket-conveyor-image-php85-tests.log` (lsof installed only in the disposable test container).
- Docker-backed harness verification passed four checks on PHP 8.4; rerun after final documentation edits, then commit/push master and confirm hosted Tests and Docker image checks.
- No credentials generated or modified; launcher requires existing credentials through environment variables.
- Security audit found 19 advisories across five locked production packages; refreshed those packages and their required Guzzle promises dependency. Updated GrumPHP and its Gitlib dependency for PHP 8.5 support while retaining PHP 8.2 compatibility. Eight locked packages changed; production Composer audit now passes and is enforced during image builds. Development-only advisories remain outside this image.

## Archived validation handoff — November 2, 2026

## Delivery

- Integration branch: `work/task-finish-benchmark-validation` in `/home/savior/Code/Studies/socket-conveyor-integration`.
- Original master checkout and its staged/unstaged work remain preserved.
- Task completion fix: skip `finish()` when no task-finished listener exists; registered listeners and incoming-message dispatch remain covered.
- Source/runtime commit: `4ea5ffb`; feature-test cleanup commit: `14bfcc4`; benchmark result/tooling commit: `f8b1857`.
- Independent final reviewer approved the scoped fixes, analyzer validity, cleanup ownership and measured results with no findings.

## Evidence

- Docker full suites: 69 tests, 267 assertions on both PHP 8.2 / OpenSwoole 25 and PHP 8.4 / OpenSwoole 26; PHPStan and Composer validation passed.
- Hosted PHP 8.2 initially exposed a test-fixture defect: Atomic wait/wakeup mutated counters on OpenSwoole 25; bounded polling in the three affected tests now preserves exact numerical assertions (`bccac4d`), with production source unchanged.
- CI retains extension/test diagnostics and a three-minute test-step timeout; the diagnostic run accurately identified the stalled router test.
- CI-repair evidence: `/tmp/socket-ci-fixed82.log`, `/tmp/socket-ci-fixed84.log`; a fresh independent review approved both modified files without findings.
- Combined harness command: `COMPOSE_PROJECT_NAME=socket-conveyor-task-runtime PATH="$PWD/docker:$PATH" harness-runtime exec scripts/verify.sh --project "$PWD"`.
- Earlier combined run exposed asynchronous test teardown; the shared helper now signals only its owned child, waits for exit/listener closure and reaps it.
- Final local verification log: `/tmp/socket-conveyor-release-verify.log`.
- Benchmark: 100 users, 6 Locust workers, 2 minutes, 256-byte payload, warmup plus 5 valid trials per server.
- Conveyor median REST publish: 887.57 requests/sec; Reverb: 649.22; ratio 1.37x, limited to this shared-host workload.
- Results: [benchmark/PERFORMANCE_RESULTS_20261102.md](benchmark/PERFORMANCE_RESULTS_20261102.md).
- Raw artifacts retained outside disposable worktrees: `/home/savior/Code/Studies/socket-conveyor/benchmark/results/runs/20261102-paired-v2/`.
- Incomplete initial warmup artifacts also retained in `20261102-paired/`; excluded from results.
- Reviewer reran 4 analyzer regressions and both runner cleanup cases, independently reanalyzed artifacts and checked source provenance/statuses.
- No secret was created or changed; existing Reverb configuration was used read-only.

## Gates and next action

- Hosted Tests workflow must be green on the final PR head; local evidence does not replace this gate.
- The existing human-controlled `review-off` switch disables harness review submission; independent native review still approved the final correctness fixes and results without findings.
- Original harness dispatch preparation failed with CalledProcessError; native workers completed in separate explicit worktrees.
- Graph service unavailable; bounded exact source and saved artifact review used instead.
- Three clean merged worker worktrees and their branches were removed after combined verification; the failed dispatch worktree is retained, and the original checkout and all raw benchmark evidence are preserved.
- Follow-ups: no general capacity claim, internet latency, multi-node fanout or every-subscriber delivery validation was requested.

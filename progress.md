# Urgent handoff — security remediation, November 4, 2026

- Branch: primary `master`; security/runtime commit `dacb29345b9fc93391f322d27e794c1402498062`, Scrutinizer configuration commit `481fa195df286b310364ebce91a308380ea7230f`. Later handoff commits contain status documentation only; no other branches or worktrees are authorized.
- Active scope: repair security audit F1–F5 using the requested security specialist, software engineer and QA engineer; preserve native Conveyor and Laravel Echo behavior.
- Security contract completed: `/home/savior/.cache/socket-conveyor-remediation/security/CONTRACT.md`; fresh engineering and QA workers are developing production and independent test patches in separate scratch copies for integration here.
- Integrated the initial 20-file production patch and a targeted dependency follow-up: PHPUnit 9.6.38, PHPCS 3.13.6 and three Symfony packages 7.4.23; full and production Composer audits passed in the dependency candidate. The original audit evidence is `/home/savior/.cache/socket-conveyor-security-2.6.0/REPORT.md`.
- Initial engineering checks: baseline 69 tests / 267 assertions, QA scoped 68 tests / 247 assertions, PHPStan and PSR12 passed in Docker; these are candidate results, not final integrated acceptance.
- Independent security review reproduced a presence-storage orphan when removal fails during close/rollback; integrated the verified repair from `/home/savior/.cache/socket-conveyor-remediation/repair`, retaining quota-charged cleanup journals through transient failure and SIGKILL. Three old-red/new-green regressions cover it.
- The combined test process previously exhausted memory because native process/table fixtures retained allocations; feature-test process isolation resolves it without weakened assertions.
- Repaired the intermittent slow-consumer HTTP timeout by explicitly setting Pusher `send_yield=false`; instrumentation established parked owner-worker pushes after buffer saturation. Independent QA passed all 14 wire cases, including actual overflow/eviction and 500/500 healthy deliveries with 200 follow-ups over 5.3 seconds. Evidence: `transport/TRANSPORT.md` and `qa-final/QA.md` in the cache.
- Final security found a preparatory presence-read exception could skip close revocation; integrated the `close-repair` patch with expected-generation revocation and admission release in finally blocks. Eight fault-stage regressions pass; fresh independent security approved the final tree (`acceptance/REVIEW.md`).
- Final integrated verification passed: 106 tests / 598 assertions, PHPStan, PSR12 and both Composer audits; all 14 wire cases passed, including real overflow/eviction, 500/500 healthy deliveries and 200 follow-ups over 5.79 seconds. Logs: `verify-final.log`, `phpcs-final.log`, `static-audit.log`, `wire-integrated/results.json` in the cache.
- Built/smoked candidate images natively on amd64/resende and arm64/MacPro; both PHP 8.5.12/OpenSwoole 26.2.0 non-root/read-only smokes passed. Trivy 0.76.1, database updated 2026-11-04T13:16:53Z, reports zero known vulnerabilities across 42 Alpine and 62 production Composer packages for each image; this does not comprehensively audit source-built PHP/OpenSwoole or unknown defects. Images remain local candidates, not published releases.
- GitHub Tests run `41914007671` and Docker image run `41914007651` passed on `dacb293`; actual Scrutinizer inspection `e7c88b04-f8ca-4803-ad87-dd2f2d19f255` errored, with no API artifacts. The owner supplied its actual error: PECL tried current OpenSwoole requiring PHP >=8.3 on PHP 7.3.15. This confirms the service runtime mismatch rather than a source-test failure.
- Integrated `.scrutinizer.yml`: explicitly select PHP 8.4.27 and OpenSwoole, install locked dependencies and required process-test utilities, run the complete suite and retain `php-scrutinizer-run` plus website PHPCS rules. The fresh engineer validated documented configuration and passing Docker platform/tests/static checks; actual hosted acceptance is still required.
- Both GitHub workflows passed on configuration commit `481fa19`: Tests `41915451263`, Docker image `41915451857`. Scrutinizer inspection `f4105808-f0fa-45ca-a126-26c27c1854dd` still errored with empty API artifacts; its new first failed command/PHP version was requested from the owner. Do not infer that the original PHP mismatch persists or that the new failure has the same cause without that log.
- Next: obtain the new error, repair any evidenced cause through a fresh engineer, then require both GitHub workflows and Scrutinizer's own current index on the exact final pushed SHA. Overall task remains unfinished while this gate fails. Detailed current-head status is `/home/savior/.cache/socket-conveyor-remediation/progress.md`; no release publication is included in this implementation task.
- Preserve the live MacPro demo, existing credentials, published `2.6.0`, unrelated work and backups.

## Roles and checks

- Security: define the fix contract, then a fresh specialist independently reviews the integrated threat cases.
- Engineering: own production source/configuration/dependency patch; Docker unit/static checks and minimal dev-tool security updates.
- QA: own independent regression tests/wire probes; reproduce old failures and verify two-app isolation, worker survival, replay denial, quota recovery and compatibility in bounded disposable containers.
- Coordinator: integrate patches, maintain this handoff, run final harness/CI checks; do not claim completion while Scrutinizer or any acceptance remains blocked.

# Archived Docker Hub release construction — November 4, 2026

- Scope: publish semver image `lotharthesavior/socket-conveyor:2.6.0` with `2.6`, `2`, and `latest` aliases; Linux amd64 and arm64. Runtime code is unchanged from `53dc6a9`, whose Tests and Docker image CI workflows passed; the release commit adds documentation and supplies the final OCI revision label.
- Release build and verification logs live under `/home/savior/.cache/socket-conveyor-release/`.
- Build amd64 natively on resende and arm64 natively on MacPro; an initial slow QEMU build was canceled. MacPro's task-owned exported build context and logs are `/tmp/socket-conveyor-release-2.6.0-53dc6a9/`; no additional Git branch or worktree was created. Source inputs come from the same committed Dockerfile allowlist. Rebuild the cached final stage with the final release revision label after committing the docs.
- Verify each architecture with `python3 docker/smoke.py lotharthesavior/socket-conveyor:2.6.0-amd64` on resende and the `2.6.0-arm64` tag on MacPro; publish only after both pass, combine the per-architecture manifests, then confirm anonymous Docker Hub access, manifest platforms, and release aliases. Pull the combined `2.6.0` image and verify its smoke too.
- README and Docker guide now show the versioned Docker Hub command; commit these docs on master, push, and require both hosted workflows green on that final commit.
- Existing Docker authentication is reused; no credentials are generated, decoded, or modified. Leave the live MacPro demo containers running and retain only master/primary worktree.

## Docker image — November 3, 2026

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

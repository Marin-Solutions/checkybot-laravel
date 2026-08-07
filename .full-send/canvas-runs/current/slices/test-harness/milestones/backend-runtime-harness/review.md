# Backend runtime harness review

- Milestone: `backend-runtime-harness`
- Spec section: `backend-runtime-harness`
- Review round: 1 (no prior review artifact was present)
- Verdict: `review_approved`

## Verification rerun

No destructive database or migration commands were run.

| Command | Exit | Evidence |
|---|---:|---|
| `bash -n scripts/runtime/backend scripts/runtime/queue-worker && find scripts/harness tests/Feature/Harness -name '*.php' -print0 \| xargs -0 -n1 php -l && composer validate --no-check-lock` | 0 | Runtime shell scripts and harness PHP test files parsed; `./composer.json is valid`. |
| `composer harness:test:backend` | 0 | 6 tests passed, 49 assertions. |
| `composer harness:test:backend` | 0 | Stability rerun: 6 tests passed, 49 assertions. |
| `vendor/bin/pest --compact` | 0 | Full suite passed: 221 tests, 537 assertions. |
| `vendor/bin/pint --test scripts/harness tests/Feature/Harness` | 0 | Pint reported passed. |
| Focused run-owned PID scan over `build/harness-runs/*/{app,worker}.pid` and `/proc/<pid>/environ` | 0 | `No run-owned backend harness app/worker PIDs are alive.` |

## Acceptance criteria

### AC-test-harness-1 — PASS

Evidence:
- `tests/Feature/Harness/BackendRuntimeHarnessTest.php` includes `starts a ready Laravel server and queue worker and stops only its recorded children` in group `AC-test-harness-1`.
- The test calls the real `scripts/runtime/backend start`, verifies recorded app and worker PIDs are owned by the run, calls real `GET /__harness/ready`, and asserts `app=ready`, `queue=ready`, and the run ID.
- The test starts an unrelated PHP process, runs `scripts/runtime/backend stop --run-dir ...`, verifies the unrelated process remains running, and waits for the recorded app/worker PIDs to stop.
- Rerun evidence: targeted suite passed twice; focused PID scan found no run-owned harness app/worker process left alive.

### AC-test-harness-2 — PASS

Evidence:
- `tests/Feature/Harness/BackendRuntimeHarnessTest.php` includes `processes a queue probe through the real HTTP API and real database worker` in group `AC-test-harness-2`.
- The runtime launches `scripts/runtime/queue-worker`, which execs Laravel `queue:work database --queue=default ...`; the test never invokes `ProcessQueueProbe::handle()` or a processor directly.
- The test submits a UUID via real `POST /__harness/queue-probes`, polls real `GET /__harness/queue-probes/{probe_id}`, and asserts `status=processed` with non-empty `processed_at`.
- Rerun evidence: targeted suite passed twice with 6 tests / 49 assertions each.

### AC-test-harness-3 — PASS

Evidence:
- `tests/Feature/Harness/BackendRuntimeHarnessTest.php` includes occupied-port, app-readiness-timeout, and premature-worker-exit tests in group `AC-test-harness-3`.
- Each scenario asserts non-zero startup and cleanup of recorded app/worker ownership; logs are asserted to contain `[stage=occupied-port]`, `[stage=app-readiness-timeout]`, or `[stage=worker-premature-exit]` in the relevant per-process log.
- `scripts/runtime/backend` failure paths call scoped `stop_runtime` and preserve `app.log`, `worker.log`, and `runtime.log` in the run directory.
- Rerun evidence: targeted suite passed twice; full suite also passed.

### AC-test-harness-4 — PASS

Evidence:
- `tests/Feature/Harness/BackendRuntimeHarnessTest.php` includes `isolates SQLite state per UUID run and refuses unsafe database configuration` in group `AC-test-harness-4`.
- The test verifies the run-local `database.sqlite` path, `runtime.json`, and SQLite tables `jobs` and `harness_queue_probes` inside the unique run directory.
- The test verifies non-SQLite (`HARNESS_DB_CONNECTION=mysql`) and out-of-run SQLite path configurations fail with `[stage=database-validation]` before unsafe state is created.
- `scripts/runtime/backend` requires UUID run directories directly under `build/harness-runs`, `sqlite`, and exactly `<run-dir>/database.sqlite`; `scripts/harness/initialize.php` repeats the run-directory path check before PDO DDL.
- Rerun evidence: targeted suite passed twice with the real run-scoped SQLite lifecycle.

## Claim truthfulness

The manifest and ledger verification claims were reproducible in this review session. The queue-related criterion was verified through the full runtime with a real running Laravel `queue:work` process, not by direct job invocation.

# Backend runtime harness milestone ledger

- Milestone: `backend-runtime-harness`
- Spec section: `backend-runtime-harness`
- Scope: backend
- Commit baseline: `aadbeae`
- Evidence completed: `2026-08-07T12:24:05+02:00`

## Owned implementation

- Added a harness-only Laravel application under `scripts/harness/` with loopback-only readiness and queue-probe routes.
- Added `scripts/runtime/backend` start/stop lifecycle management and `scripts/runtime/queue-worker` for a real `queue:work database` worker.
- Added Composer entry points for backend start, stop, and targeted Pest verification.
- Added Pest lifecycle/API/failure/isolation coverage in `tests/Feature/Harness/BackendRuntimeHarnessTest.php`.
- Added harness defaults to `.env.example`.
- No product source, product routes, or migrations were changed.

## Safety and isolation evidence

- Start accepts only `127.0.0.1`, a UUID run directory directly under workspace `build/harness-runs/`, `sqlite`, and exactly `<run-dir>/database.sqlite`.
- Each run gets isolated bootstrap cache, Laravel storage, logs, PID records, status metadata, queue tables, and SQLite state inside its unique run directory.
- Queue schema initialization uses direct PDO DDL only after exact run-path validation; no Artisan migration or destructive database command is used.
- Stop checks both recorded PID and `/proc/<pid>/environ` run ownership before signaling a process. Failure paths call the same scoped cleanup.
- Harness routes are registered only when `APP_ENV=harness` and canonical run metadata is present, and each request is loopback checked.

## Acceptance criteria evidence

### AC-test-harness-1

`starts a ready Laravel server and queue worker and stops only its recorded children` launches the real loopback Laravel development server and database queue worker, checks the real readiness JSON for `app=ready` and `queue=ready`, proves both PIDs belong to the run, and proves stop leaves an unrelated PHP process running while terminating both recorded harness children.

### AC-test-harness-2

`processes a queue probe through the real HTTP API and real database worker` submits a UUID to the real POST endpoint, polls the real GET endpoint, and observes `processed` plus non-null `processed_at`. The test never calls the job or its handler directly. It also verifies duplicate UUID validation returns 422.

### AC-test-harness-3

Three independent Pest scenarios verify occupied port, app readiness timeout, and premature worker exit. Each asserts non-zero startup, scoped process cleanup, and retained `app.log`/`worker.log` stage markers.

### AC-test-harness-4

`isolates SQLite state per UUID run and refuses unsafe database configuration` inspects the run-local SQLite tables and runtime metadata, then verifies non-SQLite and out-of-run database paths fail before creating unsafe state.

## Verification results

| Command | Exit | Result |
|---|---:|---|
| `bash -n scripts/runtime/backend scripts/runtime/queue-worker` plus PHP syntax checks and `composer validate --no-check-lock` | 0 | Shell/PHP syntax and Composer metadata valid |
| `composer harness:test:backend` (stability round 1) | 0 | 6 tests passed, 49 assertions |
| `composer harness:test:backend` (stability round 2) | 0 | 6 tests passed, 49 assertions |
| `vendor/bin/pest --compact` | 0 | 221 tests passed, 537 assertions |
| `vendor/bin/pint --test scripts/harness tests/Feature/Harness` | 0 | Formatting passed |
| live harness PID scan after verification | 0 | No run-owned harness process remained alive |

## Resolved implementation diagnostics

Initial targeted rounds exposed an ownership-check pipeline race and missing run-scoped Laravel bootstrap cache. The ownership check now reads `/proc` without a pipe, launch transitions have a short grace period, and bootstrap cache is redirected into each run directory. Two subsequent targeted rounds and the full suite passed.

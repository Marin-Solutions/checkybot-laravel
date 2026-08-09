# Test harness bootstrap — slice specification

## Purpose

Build the canonical verification harness that every later Checkybot slice can run before product implementation is accepted. The harness proves, in this environment, the lifecycle of a harness-only Laravel development server, a real Laravel queue worker, an Expo web build and static server, component tests, and a headless Playwright journey. It also defines and emits the reusable integration-verification evidence format.

This slice creates fixtures and verification infrastructure only. It must not add monitoring, alerting, dashboard, mobile, or other product behavior.

## Base requirements

- Canvas foundation mandate: prove the app development-server and queue-worker lifecycles before product work.
- Canvas foundation mandate: prove the Expo web build/serve lifecycle, harness fixture component tests, and a headless Playwright smoke journey.
- Canvas integration requirement: emit a reusable integration-verification template and one completed proof artifact from the canonical full-runtime command.
- PRD §6.1 chooses Expo for the mobile surface and PRD §7 identifies a web consumption surface; the harness must therefore establish reproducible cross-surface browser verification.

## Inherited ownership

Implementation is limited to this exact ownership set:

- `.full-send/canvas-runs/current/integration-verification-template.md`
- `.env.example`
- `composer.json`
- `package.json`
- `package-lock.json`
- `playwright.config.ts`
- `scripts/harness`
- `scripts/runtime`
- `e2e`
- `tests/Browser/Harness`
- `tests/Feature/Harness`
- Migration timestamp range: `none`

Milestone ledger, manifest, review, and handoff documents are additionally written beneath this slice's own `milestones/` directory as required by the canvas workflow.

## Scope boundaries and safety

- Harness HTTP routes and fixture code are test-only, loopback-bound, and unavailable in production.
- Runtime state is isolated beneath a unique per-run directory. Queue persistence uses only the SQLite file created inside that directory.
- The harness must fail before launch if it is configured to use a non-SQLite database or a database path outside its run directory.
- Lifecycle scripts may terminate only child PIDs they started and recorded. Cleanup runs on success, failure, timeout, and interrupt.
- No database migration is owned by this slice. No host service, Supervisor process, shared database, or Redis service is started, stopped, or reconfigured.
- The Expo fixture exists only to prove build, component, serve, API-consumption, and browser behavior; it is not a prototype or product screen.

## Harness API contract

All routes below are enabled only in the canonical harness runtime, bind to loopback, use JSON, and require no user authentication. They are consumed by the Expo web fixture and Playwright journey.

### `GET /__harness/ready`

Request:

- Header `Accept: application/json`.

Responses:

- `200`: `{ "app": "ready", "queue": "ready|starting", "run_id": "string" }`.
- `503`: `{ "app": "starting|failed", "queue": "starting|failed", "run_id": "string" }`.

The runtime is fully ready only when both `app` and `queue` equal `ready`.

### `POST /__harness/queue-probes`

Request:

- Headers `Accept: application/json` and `Content-Type: application/json`.
- Body: `{ "probe_id": "UUID string unique within the run" }`.
- Validation: `probe_id` is required, is a UUID, and has not already been accepted in the current run.

Responses:

- `202`: `{ "probe_id": "UUID string", "status": "queued", "accepted_at": "ISO-8601 datetime" }`.
- `422`: `{ "message": "string", "errors": { "probe_id": ["string"] } }`.

Acceptance must enqueue work for the real queue worker; the HTTP handler must not mark the probe processed inline.

### `GET /__harness/queue-probes/{probe_id}`

Request:

- Header `Accept: application/json`.
- `probe_id` is the UUID returned by the create route.

Responses:

- `200`: `{ "probe_id": "UUID string", "status": "queued|processed", "processed_at": "ISO-8601 datetime|null" }`.
- `404`: `{ "message": "Queue probe not found" }`.

The frontend polls this route until `processed` or until the configured test timeout produces a diagnostic failure.

## Integration evidence contract

`.full-send/canvas-runs/current/integration-verification-template.md` is the canonical template later slices copy and complete. A completed proof must record, at minimum:

- run ID, repository commit, start/end timestamps, and final pass/fail result;
- exact lifecycle, feature-test, component-test, and Playwright commands;
- loopback endpoint URLs and each child process/log identifier;
- queue probe ID, accepted timestamp, processed timestamp, and observed API states;
- component and browser test summaries;
- paths to retained backend, worker, web-server, screenshot, video, and trace evidence where produced;
- cleanup result confirming that recorded child processes are no longer alive.

The canonical integration command returns non-zero if any required stage fails or if cleanup cannot account for a recorded child process. Failure evidence remains available for diagnosis.

## Backend milestone: Laravel runtime and queue lifecycle harness

<a id="backend-runtime-harness"></a>

### Scope

Within inherited ownership, implement deterministic `scripts/runtime` lifecycle commands for a harness-only Laravel/Testbench application and its real queue worker. Implement the API fixtures under the owned harness/test paths, process supervision, readiness polling, per-process logs, bounded timeouts, run-scoped SQLite state, and idempotent cleanup. Add Pest coverage under `tests/Feature/Harness` and browser-oriented backend fixtures under `tests/Browser/Harness` as needed.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/test-harness/milestones/backend-runtime-harness/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/test-harness/milestones/backend-runtime-harness/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/test-harness/milestones/backend-runtime-harness/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/test-harness/milestones/backend-runtime-harness/handoff.md`

### Acceptance criteria

- **AC-test-harness-1:** Pest feature tests prove the backend start command launches the harness-only Laravel development server and queue worker, waits until `GET /__harness/ready` reports `app=ready` and `queue=ready`, and the paired stop command terminates only the child PIDs recorded for that run with no harness process left alive.
- **AC-test-harness-2:** A Pest end-to-end feature test submits a UUID through the real `POST /__harness/queue-probes` API, runs the real queue worker, and observes `status=processed` with a non-null `processed_at` through the real `GET /__harness/queue-probes/{probe_id}` API without invoking the queued job or processor directly.
- **AC-test-harness-3:** Pest feature tests prove occupied-port, app-readiness-timeout, and premature-worker-exit scenarios return a non-zero status, clean up recorded child processes, and retain per-process logs identifying the failed lifecycle stage.
- **AC-test-harness-4:** Pest feature tests prove the runtime creates queue state only in a unique harness run directory backed by that run's SQLite file and refuses to launch when configured with a non-SQLite or non-run-scoped database path.

## Frontend milestone: Expo web fixture and canonical Playwright verification

<a id="frontend-expo-browser-harness"></a>

### Scope

Within inherited ownership, implement an `e2e`-only Expo web fixture, locked JavaScript dependencies, deterministic package scripts for build and static serve, component tests for all contract states, Playwright configuration, and the browser smoke journey. The canonical orchestration command starts the backend and worker, builds and serves the Expo fixture, executes component and browser tests, emits evidence from the shared template, and always cleans up. No design inventory or owned screen exists, so this milestone has no product visual-parity requirement.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/test-harness/milestones/frontend-expo-browser-harness/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/test-harness/milestones/frontend-expo-browser-harness/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/test-harness/milestones/frontend-expo-browser-harness/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/test-harness/milestones/frontend-expo-browser-harness/handoff.md`

### Acceptance criteria

- **AC-test-harness-5:** From a clean checkout with locked dependencies, the canonical Expo web build command exits 0, the serve command exposes the generated harness fixture on its configured loopback URL, and stopping it leaves no recorded fixture-server process alive.
- **AC-test-harness-6:** The canonical component-test command exits 0 and tests the harness fixture's backend-starting, ready, queue-queued, queue-processed, and API-error states using mocked responses that conform to the declared harness API contract.
- **AC-test-harness-7:** The headless Playwright smoke test opens the served Expo web fixture, observes backend readiness, creates a queue probe through `POST /__harness/queue-probes`, waits while the real queue worker processes it, and displays the processed result obtained from `GET /__harness/queue-probes/{probe_id}`.
- **AC-test-harness-8:** The single canonical integration command exits 0 only after the Laravel server, queue worker, Expo web build/server, component tests, and Playwright journey all pass; it then emits a completed Markdown proof derived from `.full-send/canvas-runs/current/integration-verification-template.md` containing the run ID, commit, commands, timestamps, endpoint URLs, queue probe evidence, result summary, and log/screenshot/trace paths, and cleans up every recorded child process.

## Review expectations

Review must verify that the machine contract and this human specification agree, every path remains within inherited ownership or this slice's milestone directory, all eight criteria have executable evidence, API mocks match the real fixture contract, and no product feature or host-level service operation was introduced.

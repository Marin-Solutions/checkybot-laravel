# Integration verification — test-harness

- Spec path: `.full-send/canvas-runs/current/slices/test-harness/slice-spec.json`
- Verification run ID: `054ecff0-1819-47df-a6c4-ef9ba06e88d3`
- Result: PASS
- Proof artifact: `build/harness-runs/054ecff0-1819-47df-a6c4-ef9ba06e88d3/integration-proof.md`
- Queue evidence: `build/harness-runs/054ecff0-1819-47df-a6c4-ef9ba06e88d3/probe-evidence.json`
- Screenshot: `build/harness-runs/054ecff0-1819-47df-a6c4-ef9ba06e88d3/processed.png`
- Trace directory: `build/harness-runs/054ecff0-1819-47df-a6c4-ef9ba06e88d3/playwright`

## Safety

No destructive database or migration command was run. The integration command used `HARNESS_DB_CONNECTION=sqlite` with database file `build/harness-runs/054ecff0-1819-47df-a6c4-ef9ba06e88d3/database.sqlite`, created by the harness under its unique run directory. Asynchronous queue evidence came from a real `queue:work database` process; the worker log records `Checkybot\Harness\ProcessQueueProbe ... RUNNING` and `... DONE`.

## Commands executed during integration review

| Command | Exit | Notes |
|---|---:|---|
| `npm run harness:integration` | 0 | Built Expo web fixture, ran component tests, served static build, ran Playwright smoke against backend + real queue worker, emitted proof, cleaned child processes. |
| `composer harness:test:backend` | 0 | 6 backend harness tests passed with 49 assertions. |
| Recorded harness-owned PID scan | 0 | No app, worker, or fixture process recorded in run pid files remained alive. |

## Observed API contract evidence

From `probe-evidence.json` in run `054ecff0-1819-47df-a6c4-ef9ba06e88d3`:

- `GET /__harness/ready`: 200 with `app=ready`, `queue=ready`, `run_id=054ecff0-1819-47df-a6c4-ef9ba06e88d3`.
- `POST /__harness/queue-probes`: 202 with `status=queued`, probe `3ce1b62e-1412-4a25-90f2-6c7633dc3e23`, accepted at `2026-08-07T10:50:02.913742Z`.
- `GET /__harness/queue-probes/{probe_id}`: observed queued response with `processed_at=null`, then 200 processed response with `processed_at=2026-08-07T10:50:03.562250Z`.

## Acceptance criteria

| ID | Result | Harness evidence |
|---|---|---|
| AC-test-harness-1 | PASS | Milestone review records PASS; backend suite rerun passed and verifies start/readiness/stop with child-PID ownership. |
| AC-test-harness-2 | PASS | Milestone review records PASS; backend suite and integration run prove real HTTP POST/GET with real queue worker processing. |
| AC-test-harness-3 | PASS | Milestone review records PASS; backend suite rerun passed occupied-port, readiness-timeout, and premature-worker-exit scenarios with retained logs and cleanup. |
| AC-test-harness-4 | PASS | Milestone review records PASS; backend suite rerun passed run-directory SQLite isolation and unsafe database rejection. |
| AC-test-harness-5 | PASS | Milestone review records PASS; integration run rebuilt `build/harness-fixture`, served it on `http://127.0.0.1:45929`, stopped it, and PID scan passed. |
| AC-test-harness-6 | PASS | Milestone review records PASS; component test stage in integration passed 5/5 contract-state tests. |
| AC-test-harness-7 | PASS | Milestone review records PASS; Playwright journey consumed the built fixture, readiness endpoint, real POST, real GET polling, and displayed processed state. |
| AC-test-harness-8 | PASS | Milestone review records PASS; one canonical command orchestrated all required stages, generated completed proof from the template, and cleanup left no recorded child alive. |

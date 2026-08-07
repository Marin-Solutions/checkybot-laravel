# Test harness slice ledger

- Slice: `test-harness`
- Spec: `.full-send/canvas-runs/current/slices/test-harness/slice-spec.json`
- Integration review round: 1
- Review completed: 2026-08-07
- Outcome: `slice_approved`

## Rework budget

- Backend milestone review: round 1, approved.
- Frontend milestone ledger/review: round 2 after one manifest taxonomy repair, approved.
- Prior slice-level integration reviews found: none.
- Combined rework budget status: below exhaustion threshold; no remaining defects.

## Device evidence

No `<slice_dir>/device/device-review.md` or `<slice_dir>/device/build-failure.md` file exists for this slice. Recorded as no device evidence; review judged on milestone and runtime evidence.

## Independent integration verification

No destructive database commands, migration reset/fresh/refresh, `db:wipe`, host service controls, or system package installs were run. The canonical integration command created and used run-scoped SQLite at `build/harness-runs/054ecff0-1819-47df-a6c4-ef9ba06e88d3/database.sqlite` and a real Laravel `queue:work database` process through `scripts/runtime/queue-worker`.

| Command | Exit | Evidence |
|---|---:|---|
| `npm run harness:integration` | 0 | Full built-surface run passed: backend start, real queue worker readiness, Expo web export, component tests, static fixture server, Playwright browser journey, cleanup, proof generation. Proof: `build/harness-runs/054ecff0-1819-47df-a6c4-ef9ba06e88d3/integration-proof.md`. |
| `composer harness:test:backend` | 0 | Backend harness targeted suite passed: 6 tests, 49 assertions. |
| Recorded PID scan over `build/harness-runs/*/{app,worker,fixture}.pid` | 0 | `No recorded harness-owned PIDs alive.` |

## Acceptance-criteria ledger

| Criterion | Result | Evidence |
|---|---|---|
| AC-test-harness-1 | PASS | Backend milestone review records PASS; independent `composer harness:test:backend` passed. Test starts backend, verifies `/__harness/ready` `app=ready`/`queue=ready`, stops only recorded child PIDs. |
| AC-test-harness-2 | PASS | Backend milestone review records PASS; independent backend suite passed. Queue probe is submitted over real HTTP and processed by real `queue:work`, not direct job invocation. |
| AC-test-harness-3 | PASS | Backend milestone review records PASS; independent backend suite passed occupied-port, app-readiness-timeout, and worker-exit failure scenarios with logs and cleanup. |
| AC-test-harness-4 | PASS | Backend milestone review records PASS; independent backend suite passed run-scoped SQLite isolation and unsafe DB refusal tests. |
| AC-test-harness-5 | PASS | Frontend milestone review records PASS; `npm run harness:integration` rebuilt Expo web fixture, served it on loopback, stopped fixture, and PID scan found no recorded fixture process alive. |
| AC-test-harness-6 | PASS | Frontend milestone review records PASS; integration command ran component tests successfully: 5 tests covering backend-starting, ready, queue-queued, queue-processed, and API-error contract states. |
| AC-test-harness-7 | PASS | Frontend milestone review records PASS; Playwright in run `054ecff0-1819-47df-a6c4-ef9ba06e88d3` observed readiness, POST 202, queued GET, final processed GET 200 with non-null `processed_at`. |
| AC-test-harness-8 | PASS | Frontend milestone review records PASS; `npm run harness:integration` exited 0 only after backend, worker, build/server, component tests, Playwright, cleanup, and proof generation. |

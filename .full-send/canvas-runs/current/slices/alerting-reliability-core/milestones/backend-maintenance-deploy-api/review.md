# Review — backend-maintenance-deploy-api

Verdict: `review_approved`

Review round: 2. A prior round-one review artifact requested real queue-worker proof for AC-13 through AC-15; this review verifies the rework.

Spec reviewed: `.full-send/canvas-runs/current/slices/alerting-reliability-core/slice-spec.json`, section `backend-maintenance-deploy-api`.

## Verification re-run

Final current workspace verification:

- `./vendor/bin/pest tests/Feature/MaintenanceMode/MaintenanceModeQueueRuntimeTest.php --compact` — exit 0; 3 passed, 42 assertions.
- `./vendor/bin/pest tests/Feature/MaintenanceMode/MaintenanceModeRuntimeTest.php --compact` — exit 0; 6 passed, 105 assertions.
- `./vendor/bin/pest tests/Feature/MaintenanceMode/MaintenanceModeRuntimeTest.php tests/Feature/MaintenanceMode/MaintenanceModeQueueRuntimeTest.php tests/Feature/Alerting/ResultStateRuntimeTest.php --compact` — exit 0; 17 passed, 225 assertions.
- `./vendor/bin/pest --compact` — exit 0; 259 passed, 1032 assertions.
- `composer analyse -- --no-progress` — exit 0; no errors.
- `./vendor/bin/pint --test tests/Feature/MaintenanceMode` — exit 0; passed.

The ledger's exact assertion counts for the queue-runtime/full/regression runs are stale after the final rework, but the commands are reproducibly passing in the current workspace. No destructive database command or host-service control was run.

## Acceptance criteria results

| Criterion | Result | Evidence |
|---|---|---|
| `AC-alerting-reliability-core-11` | PASS | `MaintenanceModeRuntimeTest.php` passes and covers POST validation for `operation_id`, `duration_minutes` bounds, and `scope`; 401 missing/invalid credentials; 403 read-token/global/cross-project token requests; 201 creation; 200 idempotent replay; and 409 overlapping active request. |
| `AC-alerting-reliability-core-12` | PASS | `MaintenanceModeRuntimeTest.php` passes and verifies API/CLI use of `CreateMaintenanceMode`, project/global silencing behavior, GET current effective scope and `ends_at`, and DELETE policy enforcement with 204 on authorized clear. |
| `AC-alerting-reliability-core-13` | PASS | `MaintenanceModeQueueRuntimeTest.php` posts ingested results, processes them with an independent `queue:work` process, verifies persisted warn/down/healthy transitions all have `maintenance_suppressed = true`, and verifies zero notification intents/outbox notification events after the real relay/worker path. |
| `AC-alerting-reliability-core-14` | PASS | `MaintenanceModeRuntimeTest.php` and `MaintenanceModeQueueRuntimeTest.php` verify expiry and early-clear dispatch registered catch-up jobs, real workers create one grouped incident per affected project with all current non-healthy monitors, healthy-only state emits none, duplicate jobs are idempotent, and two worker processes reach a catch-up barrier before release for the concurrent claim proof. |
| `AC-alerting-reliability-core-15` | PASS | `MaintenanceModeRuntimeTest.php` and `MaintenanceModeQueueRuntimeTest.php` verify hash-only deploy-token storage, independent read/write authorization after expiry/revocation/rotation, scheduler registration every minute with `withoutOverlapping`, durable overdue catch-up while no worker is running, later real-worker catch-up, and no early processing of active maintenance. |

## Notes

- The async/seam evidence now satisfies the lane rule: queued result processing, relay delivery, expiry, early-clear, and duplicate catch-up proofs flow through real `queue:work` processes rather than direct processor/job invocation.
- Runtime tests use workspace-local throwaway SQLite files created under `build/maintenance-mode-tests/` and `build/maintenance-queue-runtime-tests/` by invoking additive migration `up()` methods directly; no Laravel destructive migration command was used.

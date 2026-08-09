# Backend Result State Runtime — Verification Ledger

Milestone: `backend-result-state-runtime`

## Implementation

- Added immutable normalized result DTOs and `MonitorResultIngestionInterface` binding with operation-ID payload hashing and idempotent queue dispatch.
- Added `ProcessMonitorResult` and transactional state processing using foundation `MonitorState`, `MonitorTransition`, and `OutboxEvent` records.
- Added actual producer recheck requests at +10s/+30s, due-request command/job, stale-request cancellation, and overlap-safe scheduler registration.
- Added pull failure confirmation and push three-sample warn/critical/recovery hysteresis.
- Added testing/harness-only loopback result and receipt endpoints.
- Added workspace-local SQLite tests, including a barrier-synchronized three-process race and a real database queue worker/outbox relay journey.

## Acceptance evidence

| Criterion | Evidence |
|---|---|
| AC-alerting-reliability-core-1 | `ResultStateRuntimeTest.php`: valid pull/push DTO acceptance, non-finite/source-incompatible rejection, immutable operation conflict, one queued `ProcessMonitorResult`, and duplicate replay. |
| AC-alerting-reliability-core-2 | Clock-controlled pull tests verify warn on first failure, producer requests at +10/+30, down only on third actual result, silent pre-alarm recovery, and cancellation of stale scheduled requests. |
| AC-alerting-reliability-core-3 | Table-driven push cases verify three-sample warn/critical confirmation, default five-point recovery boundary, interrupt resets, and oscillation without transition churn. |
| AC-alerting-reliability-core-4 | Component tests verify project isolation, entered/reason metadata, immutable ordered transitions, atomic state/transition/outbox rollback, and barrier-synchronized independent-process ordering/deduplication. |
| AC-alerting-reliability-core-5 | Feature journey posts the harness endpoint, runs a real database queue worker, executes `checkybot:foundation-relay`, runs the delivery worker, and observes `agent-v2-expanded-monitors` plus persisted state/transition through the receipt endpoint. |

## Verification results

| Command | Exit | Result |
|---|---:|---|
| `./vendor/bin/pest tests/Feature/Alerting/ResultStateRuntimeTest.php --compact` | 0 | 8 passed, 78 assertions |
| `./vendor/bin/pest --compact` | 0 | 246 passed, 837 assertions |
| `./vendor/bin/phpstan analyse --no-progress` | 0 | No errors |
| `./vendor/bin/pint --test src/Domain/Alerting src/CheckybotLaravelServiceProvider.php src/Models/MonitorState.php src/Models/MonitorTransition.php database/migrations/2026_08_06_010000_create_alerting_result_runtime_tables.php tests/Feature/Alerting` | 0 | Passed |

## Database safety

No destructive Artisan migration or database lifecycle command was run. Tests create UUID-named SQLite files under `build/alerting-runtime-tests/` inside the workspace, apply migrations directly to those throwaway files, and remove them afterward.

# Integration verification — alerting-reliability-core

Spec: `.full-send/canvas-runs/current/slices/alerting-reliability-core/slice-spec.json`  
Reviewed commit: `a3b23e34208c528b7ccfa6531e3ea259a7089f6c`

## Commands run in this integration review

| Command | Exit | Evidence |
|---|---:|---|
| `./vendor/bin/pest --compact` | 0 | 262 passed, 1080 assertions. Covers all alerting, grouping, maintenance, watchdog, foundation, and package tests. |
| `./vendor/bin/phpstan analyse --no-progress --error-format=table` | 0 | PHPStan reported `[OK] No errors`. |
| `npm run harness:integration` | 0 | Built Expo web fixture with `expo export`, ran component tests, served the built output, started Laravel harness with a real database queue worker, and passed Playwright browser tests. Proof: `build/harness-runs/668a2335-9a10-4c10-ae96-1151c0a9e424/integration-proof.md`. |
| `tests/Feature/Alerting/runtime-stage start && tests/Feature/Alerting/runtime-stage test; status=$?; tests/Feature/Alerting/runtime-stage stop; exit $status` | 0 | Canonical alerting runtime Playwright passed. Evidence JSON: `build/incident-grouping-runtime/evidence.json`; worker log: `build/harness-runs/c182cd66-c7de-4b6a-a734-845372ac62f4/worker.log`. |
| `./vendor/bin/pint --test src tests/Feature/Alerting tests/Feature/MaintenanceMode database/migrations routes/maintenance.php` | 0 | Pint returned `{"tool":"pint","result":"passed"}`. |

## Runtime and surface requirements

- Queue-worker requirement: satisfied. The alerting runtime worker log shows actual queued jobs running through `queue:work`: `ProcessMonitorResult`, `ProcessIncidentTransition`, `EmitIncidentIntent`, and `DeliverFoundationEvent` all ran and completed in `build/harness-runs/c182cd66-c7de-4b6a-a734-845372ac62f4/worker.log`.
- Slice runtime seam: satisfied. Playwright posted normalized pull results through `/__harness/alerting/results`, exercised the registered relay/grouping jobs, and read `/__harness/alerting/receipts/{operation_id}`; `build/incident-grouping-runtime/evidence.json` records `execution.direct_processor_calls: 0`.
- Built production-shaped surface: satisfied for this backend/package slice by `npm run harness:integration`, which ran `expo export` to `build/harness-fixture`, served that built output through the fixture static server, and passed headless Playwright with the real Laravel queue worker.
- Database safety: no destructive migration/database command was run. Harness startup refused non-SQLite databases and logged run-scoped SQLite verification before additive `migrate --force`.
- Device evidence: no device evidence file exists; recorded as no device evidence.

## API-contract verification

| Contract | Integration evidence |
|---|---|
| `MonitorResultIngestionInterface::ingest(NormalizedMonitorResult)` | `ResultStateRuntimeTest.php` and full suite prove DTO validation, idempotency, one job per operation, pull retry policy, and push hysteresis. Runtime Playwright proves the HTTP harness route reaches the queue-safe ingestion seam without direct processor calls. |
| `OUTBOX NotificationIntentCreated` | `IncidentGroupingTimelineTest.php`, `MaintenanceModeQueueRuntimeTest.php`, and runtime evidence prove grouped incident/recovery payloads with stable thread keys, affected monitor identities, problem filters, and real relay/worker processing. |
| `IncidentTimelineReadModel::forMonitor(AuthorizedMonitorIdentity)` | `IncidentGroupingTimelineTest.php` component/read-model assertions cover authorized reads, current state, transitions, durations, groups, suppression flags, nullable annotation slots, and cross-project denial. |
| `POST /api/v1/maintenance-modes` | `MaintenanceModeRuntimeTest.php` covers validation, token/operator authorization, 201 creation, 200 idempotent replay, 401/403/409 responses, and project-token scope constraints. |
| `GET /api/v1/maintenance-modes/current` | `MaintenanceModeRuntimeTest.php` covers token/operator reads, effective project/global scope, `ends_at`, and policy denial. |
| `DELETE /api/v1/maintenance-modes/{maintenance_mode}` | `MaintenanceModeRuntimeTest.php` and `MaintenanceModeQueueRuntimeTest.php` cover scope policy, 204 clear, queued catch-up, and real-worker processing. |
| `POST /__harness/alerting/results` and `GET /__harness/alerting/receipts/{operation_id}` | Canonical runtime Playwright passed through real HTTP routes; evidence JSON records processed receipts, current state/transitions, scheduled retries, incident groups, notification intents, and fake-consumer receipts. |
| `GET {CHECKYBOT_WATCHDOG_URL}` outbound | `ExternalWatchdogTest.php` covers HTTPS URL handling, one GET per due minute, bounded timeout, user agent, success/failure diagnostics, disabled config, redaction, and scheduler coexistence. |

## Acceptance criteria results

| ID | Result | Evidence |
|---|---|---|
| AC-alerting-reliability-core-1 | PASS | Milestone review and full suite verify valid pull/push DTOs, incompatible/non-finite rejection, one queued `ProcessMonitorResult` per operation, immutable operation conflict, and duplicate replay without a second mutation. |
| AC-alerting-reliability-core-2 | PASS | Clock-controlled pull tests verify healthy→warn without notification, producer rechecks at +10/+30s, warn→down only after third actual failed attempt, success before third failure returns healthy silently, and sub-30s downtime has zero notification intents. |
| AC-alerting-reliability-core-3 | PASS | Table-driven hysteresis tests verify three consecutive warn/critical samples, three recovery samples at warn minus default 5-point delta, interrupt resets, and no churn around thresholds. |
| AC-alerting-reliability-core-4 | PASS | State component tests verify project-isolated current state, immutable ordered transitions with metadata, atomic outbox write/rollback, and concurrent processing without ordering regression or duplicate transitions. |
| AC-alerting-reliability-core-5 | PASS | Real-worker seam test posts to the harness API, runs `queue:work`, executes foundation relay/delivery worker, and observes persisted state plus `agent-v2-expanded-monitors` receipts. |
| AC-alerting-reliability-core-6 | PASS | Grouping tests prove no intent before 30s and exactly one incident intent after the deadline with same-project members, maximum severity, stable thread key, and matching problem filter. |
| AC-alerting-reliability-core-7 | PASS | Grouping tests prove within-five-minute downs update the same group/intent/thread/filter, boundary downs create a new group, and duplicate deliveries leave counts unchanged. |
| AC-alerting-reliability-core-8 | PASS | Grouping tests prove partial/recovering members emit no recovery and final healthy emits one grouped recovery with original thread key, all identities, and downtime. |
| AC-alerting-reliability-core-9 | PASS | Read-model tests prove authorized timeline contents and cross-project denial. |
| AC-alerting-reliability-core-10 | PASS | Runtime Playwright evidence shows sub-30s recovery with zero intents, confirmed multi-monitor failure with one incident intent, final recovery with one recovery intent, real queue worker and relay, and no direct processor calls. |
| AC-alerting-reliability-core-11 | PASS | Maintenance API feature tests cover validation, 401/403, authorized 201, idempotent 200 replay, and overlapping 409. |
| AC-alerting-reliability-core-12 | PASS | Maintenance tests verify API and CLI share the action, project/global silencing, GET current, and DELETE authorization/204 behavior. |
| AC-alerting-reliability-core-13 | PASS | Maintenance queue runtime test drives transitions through ingestion and a real queue worker; persisted transitions have `maintenance_suppressed=true` and notification intents/outbox notification events remain zero. |
| AC-alerting-reliability-core-14 | PASS | Maintenance runtime tests prove expiry/clear catch-up jobs re-evaluate persisted state, emit one grouped incident per affected project, emit none for healthy-only states, and remain idempotent under duplicate/concurrent jobs through real workers. |
| AC-alerting-reliability-core-15 | PASS | Token/scheduler tests prove hash-only tokens, separate read/write ability enforcement after expiry/revocation/rotation, overdue catch-up while worker was unavailable, later real-worker catch-up, and no early active-record processing. |
| AC-alerting-reliability-core-16 | PASS | Watchdog tests prove minutely HTTPS GET dispatch, overlap prevention, bounded timeout, 2xx success recording, and scheduler coexistence with relay/retry/grouping/maintenance entries. |
| AC-alerting-reliability-core-17 | PASS | Watchdog failure-path tests prove disabled config sends no request, timeout/transport/non-2xx produce redacted local diagnostics, other scheduled tasks remain runnable, and next due minute can succeed. |

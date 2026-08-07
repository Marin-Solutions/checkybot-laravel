# Review: backend incident grouping/read-model

Verdict: review_approved

Review round: 1 (no prior review artifact existed at this milestone review path).

Spec reviewed: `.full-send/canvas-runs/current/slices/alerting-reliability-core/slice-spec.json`, section `backend-incident-grouping-read-model`.

## Verification re-run

No destructive database commands were run. The full-runtime harness logged `[stage=database-verified] connection=sqlite database=/home/ploi/workspaces/agent-canvas-b1631f73-b32f-4463-ae9b-0633a2a40625-checkybot-laravel/build/harness-runs/120e174c-4678-4465-b737-afd0d7e6afa0/database.sqlite` before its non-destructive `migrate --force` against the run-scoped SQLite file.

| Command | Exit | Evidence |
| --- | ---: | --- |
| `./vendor/bin/pest --compact` | 0 | 250 passed, 885 assertions. |
| `composer analyse` | 0 | PHPStan completed with no errors. |
| `./vendor/bin/pest tests/Feature/Alerting/IncidentGroupingTimelineTest.php --compact` | 0 | 4 passed, 48 assertions. |
| `./vendor/bin/pest tests/Feature/Alerting --compact` | 0 | 12 passed, 126 assertions. |
| `./vendor/bin/pint --test src/Domain/Alerting src/Models/MonitorTransition.php database/migrations/2026_08_06_010100_create_alerting_incident_group_tables.php tests/Feature/Alerting/IncidentGroupingTimelineTest.php` | 0 | Pint returned `passed`. |
| `tests/Feature/Alerting/runtime-stage start` | 0 | Started loopback harness at `127.0.0.1:25706`; runtime log shows run-scoped SQLite DB and `queue=ready`. |
| `tests/Feature/Alerting/runtime-stage test` | 0 | Playwright `IncidentGroupingRuntime.spec.ts` passed, producing `build/incident-grouping-runtime/evidence.json`. |
| `tests/Feature/Alerting/runtime-stage stop` | 0 | Stopped only the owned harness run `120e174c-4678-4465-b737-afd0d7e6afa0`; no active runtime state remains. |

## Acceptance criteria

| ID | Result | Evidence |
| --- | --- | --- |
| AC-alerting-reliability-core-6 | PASS | `IncidentGroupingTimelineTest.php` covers the 30-second hold: direct execution at 29 seconds and scheduler recovery leave zero intents, then the deadline emits a single incident intent. Assertions verify two same-project affected monitors, max `critical` severity, stable thread key, and `problem_filter` monitor UUIDs. Targeted and full test suites passed. |
| AC-alerting-reliability-core-7 | PASS | `IncidentGroupingTimelineTest.php` proves a down at +4m59s updates the original group, intent public ID, operation ID, thread key, and filter; a +5m01s transition creates a second group/incident; replaying the same transition leaves group/member/intent/outbox counts unchanged. Targeted and full test suites passed. |
| AC-alerting-reliability-core-8 | PASS | `IncidentGroupingTimelineTest.php` proves recovering and partial healthy transitions create zero recovery intents; the final healthy transition creates exactly one recovery using the incident thread key, both affected identities, and first-down-to-final-healthy downtime. Targeted and full test suites passed. |
| AC-alerting-reliability-core-9 | PASS | `IncidentTimelineReadModel` test assertions cover authorized current state, entered time, ordered transition states and durations, group ID/closed time/affected monitor, reason codes, maintenance suppression flags, nullable annotation slot values, and cross-project authorization rejection. Targeted and full test suites passed. |
| AC-alerting-reliability-core-10 | PASS | Full-runtime Playwright posted real pull attempts through `/__harness/alerting/results` and read `/__harness/alerting/receipts/{operation_id}`. `worker.log` shows real `queue:work` processing `ProcessMonitorResult`, `ProcessIncidentTransition`, `EmitIncidentIntent`, and `DeliverFoundationEvent`. `evidence.json` shows the sub-30-second recovery has zero `notification_intents`, the confirmed multi-monitor failure has one incident intent with two monitors, the final recovery has one recovery intent with the same thread key, relay runs exited 0, and `execution.direct_processor_calls` is 0. |

## Data integrity / migration review

- New migration timestamp `2026_08_06_010100` is inside the declared `2026_08_06_010000-2026_08_06_019999` range.
- Migration has a reversible `down()` and adds nullable/defaulted columns to existing `monitor_transitions`, so existing rows are handled safely.
- Uniqueness/idempotency constraints are present for group membership identity, group/phase notification intents, notification operation IDs, transition links, project lock rows, and thread keys.
- Incident grouping and intent writes run inside database transactions; delayed incident emission is dispatched with `afterCommit`, and runtime evidence confirms queued jobs flowed through a real worker.

No changes requested.

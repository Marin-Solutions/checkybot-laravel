# Backend incident grouping/read-model ledger

Milestone: `backend-incident-grouping-read-model`  
Task: `b6de40c8-8725-4c22-b7f8-193cdcf25aef`

## Scope implemented

- Added project-isolated incident groups, unique monitor membership, transition links, and one logical notification-intent row per group/phase in migration `2026_08_06_010100`.
- Added queued `ProcessIncidentTransition` and `EmitIncidentIntent` jobs. The first down schedules a 30-second hold; `checkybot:alerting-groups` provides overlap-safe scheduler recovery. Open-group selection uses the latest accepted member's sliding five-minute window.
- Added atomic incident/recovery intent and outbox writes using deterministic operation/intent UUIDs, a stable thread key, maximum severity, affected identities, and the live problems filter. Duplicate jobs/deliveries preserve logical counts.
- Added final-member recovery handling and silent closure for groups that recover before the collection deadline.
- Added `IncidentTimelineReadModel` with authorization-context enforcement, ordered transition durations, incident membership, suppression flags, and provider-neutral nullable annotation slots.
- Expanded the testing receipt API with real incident groups/intents and registered an alerting outbox consumer adapter. Push-channel transport remains excluded.
- Added a canonical API-only Playwright journey and staged run-scoped SQLite runtime controller. It uses the loopback HTTP routes, real database queue worker, registered result/grouping jobs, and foundation relay; it calls no processor/action directly.

## Acceptance evidence

| Acceptance criterion | Evidence |
| --- | --- |
| AC-alerting-reliability-core-6 | `IncidentGroupingTimelineTest.php`: before 30 seconds there are zero intents; at the deadline there is one incident intent with both identities, maximum critical severity, stable thread key, and exact problems filter. Scheduler recovery dispatch is asserted. |
| AC-alerting-reliability-core-7 | `IncidentGroupingTimelineTest.php`: +4m59s updates the same group/intent/operation/thread/filter; +5m01s from the latest member creates a second group; replay leaves member, intent, and outbox counts unchanged. |
| AC-alerting-reliability-core-8 | `IncidentGroupingTimelineTest.php`: recovering and partial healthy transitions create no recovery; final healthy creates one recovery with the original thread, all identities, and first-down-to-final-healthy downtime. |
| AC-alerting-reliability-core-9 | `IncidentGroupingTimelineTest.php`: authorized timeline assertions cover current state, entered time, ordered durations, group data, reasons, suppression flags, nullable slots, and cross-project denial. |
| AC-alerting-reliability-core-10 | `build/incident-grouping-runtime/evidence.json`: real HTTP attempts show a 20-second confirmed outage with zero intents, one two-monitor incident intent, and one final recovery intent. `relay_runs` records exit 0 and 11 relayed events; `execution.direct_processor_calls` is 0. |

## Verification results

All database-backed tests created unique SQLite files under this workspace. The runtime launcher recorded `[stage=database-verified] connection=sqlite` for its run-scoped `build/harness-runs/<uuid>/database.sqlite` before safe, non-destructive migration. No destructive database command was used.

| Command/stage | Exit | Result |
| --- | ---: | --- |
| `./vendor/bin/pest tests/Feature/Alerting/IncidentGroupingTimelineTest.php --compact` | 0 | 4 passed, 48 assertions |
| `./vendor/bin/pest tests/Feature/Alerting --compact` | 0 | 12 passed, 126 assertions |
| `./vendor/bin/pest --compact` | 0 | 250 passed, 885 assertions; log: `build/incident-grouping-full-pest.log` |
| `composer analyse` | 0 | PHPStan: no errors |
| Pint `--test` over owned PHP paths | 0 | passed |
| `tests/Feature/Alerting/runtime-stage start` | 0 | run-scoped SQLite app and real database worker ready |
| `tests/Feature/Alerting/runtime-stage test` | 0 | Playwright: 1 passed in 15.3s |
| `tests/Feature/Alerting/runtime-stage stop` | 0 | only the owned harness app/worker processes were stopped |

Diagnostic rounds first exposed and then corrected a recovery downtime integer cast, a missing predecessor-test migration, relay environment propagation, and sync-dispatch inside the SQLite serialization transaction. Final results above are the post-correction runs.

# Review: backend-ai-annotation-seams

Verdict: approved

Review round: 2. Prior review artifact recorded round 1 changes requested for AC-ai-incident-annotations-7, AC-ai-incident-annotations-8, and AC-ai-incident-annotations-9 queue-worker evidence. This re-review confirms those defects were addressed; rework budget is not exhausted.

## Verification rerun

- `vendor/bin/pest tests/Feature/AiAnnotations --compact` — passed: 26 tests / 257 assertions, duration 87.59s.
- `vendor/bin/phpstan analyse --no-progress` — passed: no errors.
- `vendor/bin/pint --test` — passed.

The ledger's narrower `vendor/bin/pest tests/Feature/AiAnnotations/AnnotationSeamsTest.php --compact` evidence is included in the rerun of the full `tests/Feature/AiAnnotations` target. No destructive Artisan migration/database commands were run. The reviewed feature tests create per-test workspace-local SQLite files under `build/ai-annotation-seam-tests` and invoke migration `up()` methods directly.

## Acceptance criteria

| ID | Result | Evidence |
| --- | --- | --- |
| AC-ai-incident-annotations-6 | Pass | `AnnotationSeamsTest.php` group `AC-ai-incident-annotations-6` passed. It resolves the AI service provider, route names, `AiAnnotationOutboxDispatcher`, and `GenerateIncidentAnnotation` queued job; dispatches through the decorated `FoundationEventDispatcher`; verifies exactly one queued job for an enabled persisted server-down transition; and verifies zero additional queued jobs for disabled projects, warn/recovering/healthy transitions, website/non-server identities, missing/mismatched transitions, duplicate delivery, and production harness receipt access. |
| AC-ai-incident-annotations-7 | Pass | Worker-backed tests now dispatch serialized `MarinSolutions\CheckybotLaravel\Jobs\AiAnnotations\GenerateIncidentAnnotation` jobs onto the database queue and drain them through separate PHP processes that invoke the real `queue:work` command. Evidence verifies the `RedactedLogSnippetProvider` receives the transition's authorized project/server identity, bounded UTC incident window, sources, and line limit; the stored root cause is recursively redacted and linked to the existing transition; duplicate replay produces one snippet request/provider call/reservation/annotation; `alert_eligible=true` is skipped with no provider call, charge, or annotation; and a barrier-synchronized two-worker race still produces one provider call, one settled reservation, and one annotation. |
| AC-ai-incident-annotations-8 | Pass | The lifecycle snapshot test passed with both successful and provider-failed AI paths dispatched as jobs and processed by real queue workers. It snapshots `monitor_states`, `monitor_transitions`, `alerting_incident_groups`, `outbox_events` notification intents, `alerting_notification_intents`, and `push_operations` after static down confirmation, then proves those rows remain unchanged after AI processing except for AI-owned operation/budget/annotation rows and the separately created fixture transition/outbox for the failed case. |
| AC-ai-incident-annotations-9 | Pass | The failure matrix passed through the real queue worker for disabled-on-recheck, denied snippets, empty snippets, exhausted budget, unavailable provider, invalid output, and ambiguous transport acceptance, with duplicate queued deliveries in each case. Assertions verify a single terminal skipped/failed receipt, no root cause, no notification side-effect count changes, at most one provider call only for provider-level failures, and no double reservation/spend. |
| AC-ai-incident-annotations-10 | Pass | The end-to-end Pest test passed. It enables settings through authenticated `PUT /checkybot/ai-annotations/settings`, posts three authenticated `POST /api/v2/agent-reports`, drains the evaluator and annotation jobs with real queue-worker helper processes, runs the foundation outbox relay, then observes the loopback receipt and real X-Inertia monitor-detail route. The receipt/timeline expose the same redacted `root_cause` and omit provider credentials, model, prompt/snippet, token/cost, and failure metadata. |

## Data integrity / code quality notes

- New migrations remain inside the slice-owned `2026_08_06_900000-2026_08_06_909999` range and include `down()` methods.
- Database uniqueness constraints back the operation idempotency rules: unique `operation_id`, unique `transition_operation_id`, unique reservation `operation_id`, project setting uniqueness, and budget bucket scope/month uniqueness.
- Queue dispatch uses the queued job path and `afterCommit()` from the dispatcher; the queue-worker evidence no longer invokes `ProcessIncidentAnnotation` directly for AC 7-9.
- No clear structural regression or file-size issue was found in the reviewed seam implementation.

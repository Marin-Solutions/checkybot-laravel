# Backend AI annotation seams ledger

## Scope

Implemented only `backend-ai-annotation-seams`: the AI outbox decorator, transition eligibility adapter, queued processor, bounded agent-log context request, harness receipt, and latest `root_cause` timeline integration. This rework specifically replaces direct processor evidence with database-queued jobs drained by real `queue:work` processes.

## Acceptance evidence

- **AC-ai-incident-annotations-6** — Registration and dispatcher coverage resolves the AI module, decorated `FoundationEventDispatcher`, routes, and queued job. It proves exactly one job for an enabled persisted server-down transition and no additional job for disabled, non-down, unsupported, missing, mismatched, duplicate, or production harness-route cases.
- **AC-ai-incident-annotations-7** — `AnnotationSeamsTest.php` now serializes duplicate `GenerateIncidentAnnotation` jobs onto the database queue and drains them with `Support/ai-annotation-worker.php`, which invokes the real `queue:work` command. Cross-process logs prove one authorized bounded snippet request and one provider call. A separate worker-backed boundary case returns `alert_eligible=true` and proves the operation is skipped with no provider call, reservation, or annotation. The concurrency test queues two duplicate jobs and releases two independent `queue:work --once` processes through a filesystem barrier, proving one provider call, settlement, and annotation.
- **AC-ai-incident-annotations-8** — Both successful and provider-failed AI paths are now queued and processed by separate real workers. Complete snapshots of monitor state, transitions, incident groups, notification-intent outbox rows, notification intents, and push operations remain byte-for-byte unchanged after both workers finish.
- **AC-ai-incident-annotations-9** — Every disabled-on-recheck, denied, empty, exhausted-budget, unavailable-provider, invalid-output, and ambiguous-transport case now queues duplicate serialized jobs and drains them with a real database queue worker. Assertions prove one terminal receipt, no root cause or notification side effect, no double reservation/spend, and at most one provider call.
- **AC-ai-incident-annotations-10** — Existing end-to-end coverage continues to enable settings over HTTP, post three agent reports, drain evaluator and annotation jobs with real workers, relay the foundation outbox, and verify receipt and monitor timeline HTTP responses.

## Verification results

| Command | Exit | Result |
| --- | ---: | --- |
| `vendor/bin/pest tests/Feature/AiAnnotations/AnnotationSeamsTest.php --compact` | 0 | 13 tests, 132 assertions passed (`build/backend-ai-annotation-seams-rework-pest.log`) |
| `vendor/bin/pest tests/Feature/AiAnnotations --compact` | 0 | 26 tests, 257 assertions passed (`build/backend-ai-annotation-seams-pest.log`) |
| `vendor/bin/phpstan analyse --no-progress` | 0 | No errors (`build/backend-ai-annotation-seams-rework-phpstan.log`) |
| `vendor/bin/pint --test` | 0 | Passed (`build/backend-ai-annotation-seams-rework-pint.log`) |
| `vendor/bin/pest --compact` | 0 | 350 tests, 2258 assertions passed (`build/backend-ai-annotation-seams-full-pest.log`) |

All feature tests create unique workspace-local SQLite files under `build/` and invoke migration `up()` methods directly. No Artisan migration command or destructive database command was run.

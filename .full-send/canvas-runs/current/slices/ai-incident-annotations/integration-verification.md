# Integration verification: ai-incident-annotations

## Commands run by integration review

| Command | Result | Evidence |
| --- | --- | --- |
| `node build/ai-annotation-runtime/review-built-runtime.mjs` | Pass | Prepared run `91277bcb-019f-48b9-8caa-763a39e7c44f`; exported Expo web build to `build/ai-annotation-runtime/91277bcb-019f-48b9-8caa-763a39e7c44f/expo-web-build`; served it through the static harness server; ran Playwright against the built surface with Laravel backend and real database queue worker. |
| Sub-step: effective DB config print before migration | Pass | Output showed `database.default = sqlite` and active connection database `/home/ploi/workspaces/agent-canvas-b1631f73-b32f-4463-ae9b-0633a2a40625-checkybot-laravel/build/ai-annotation-runtime/91277bcb-019f-48b9-8caa-763a39e7c44f/database.sqlite`. |
| Sub-step: `php scripts/harness/artisan migrate --force --no-interaction` | Pass | Non-destructive migration only, after workspace-local SQLite verification; AI migrations `2026_08_06_900000` and `2026_08_06_900100` ran. |
| Sub-step: `expo export tests/Feature/WebDashboard/RuntimeApp --platform web --output-dir .../expo-web-build --clear` | Pass | Static web export created `index.html` and bundled web JS. |
| Sub-step: Playwright AI annotation runtime test | Pass | `tests/Component/AiAnnotations/AiAnnotationRuntime.spec.ts` passed 1/1 against built static surface. |
| Queue worker evidence | Pass | `build/ai-annotation-runtime/91277bcb-019f-48b9-8caa-763a39e7c44f/worker.log` contains real `queue:work database` processing entries for evaluator, alerting, foundation delivery, and `MarinSolutions\\CheckybotLaravel\\Jobs\\AiAnnotations\\GenerateIncidentAnnotation RUNNING/DONE`. |

No destructive Artisan/database command was run. No host-level package installation, service shutdown, Supervisor control, database/Redis shutdown, or database drop/wipe command was attempted.

## Production-shaped surface evidence

- Built surface type: Expo web export served by `scripts/harness/static-server.mjs`.
- Built artifact directory: `build/ai-annotation-runtime/91277bcb-019f-48b9-8caa-763a39e7c44f/expo-web-build`.
- Frontend server log: `build/ai-annotation-runtime/91277bcb-019f-48b9-8caa-763a39e7c44f/frontend.log` (`[fixture-ready] http://127.0.0.1:41237`).
- Screenshot: `build/ai-annotation-runtime/91277bcb-019f-48b9-8caa-763a39e7c44f/ai-annotation-monitor-detail.png`.
- Trace: `build/ai-annotation-runtime/91277bcb-019f-48b9-8caa-763a39e7c44f/ai-annotation-trace.zip`.
- Structured evidence: `build/ai-annotation-runtime/evidence-built.json`.

## API contract integration checks

| Contract item | Result | Evidence |
| --- | --- | --- |
| `GET /checkybot/ai-annotations/settings` | Pass | Built-runtime evidence records authenticated GET status 200, `enabled=false`, `version=0`, `provider_configured=true`, no credential exposure, and budget period/remaining fields. Milestone review AC-2 covers unauthenticated/unauthorized/absent setting variants. |
| `PUT /checkybot/ai-annotations/settings` | Pass | Built-runtime evidence records authenticated PUT status 200 with request body exactly `{enabled:true, version:0}`, no project override, response `enabled=true`, `version=1`, budget fields, and no credential. AC-2 milestone review covers CSRF, stale 409, unauthorized, and invalid config 422. |
| `OUTBOX monitor.transitioned -> IncidentConfirmedForAnnotation` | Pass | Built journey creates static incidents through `POST /api/v2/agent-reports`, runs registered relay (`Relayed 2 foundation event(s).` twice), and worker log shows `DeliverFoundationEvent` and AI job execution. AC-6 and AC-10 milestone reviews cover dispatcher registration/eligibility/duplicate/skip cases. |
| `RedactedLogSnippetProvider::forIncident` | Pass | AC-7 milestone review confirms authorized project/server identity, bounded window, `alert_eligible=false` requirement, and idempotent queued processing. Built evidence confirms completed receipt and redacted root_cause after real worker processing. |
| Outbound provider request | Pass | AC-4 and AC-5 milestone reviews confirm HTTPS-only provider, bounded timeouts, idempotency key, no redirects/retries, capped payload, pre-egress recursive redaction, and typed redacted failures. Runtime uses harness fake provider with redacted output. |
| `GET /checkybot/monitors/{type}/{monitor_uuid}` | Pass | Built runtime verifies real X-Inertia monitor detail for opted-out root cause unavailable and enabled redacted root-cause paragraph in the shared UI; unrelated annotation slots remain preserved. |
| Harness receipt route | Pass | Built runtime polls `GET /__harness/ai-annotations/receipts/{operation_id}` to completed status and verifies root_cause plus unchanged notification side-effect counts. AC-6 milestone review confirms production harness-route access queues zero/route absent behavior. |

## Ownership integration

- Implemented package equivalents for owned app paths under `src/Domain/AiAnnotations`, `src/Http/Controllers/AiAnnotationSettingsController.php`, and `src/Jobs/AiAnnotations`.
- Owned config and route exist: `config/ai-annotations.php`, `routes/ai-annotations.php`.
- Owned migrations are in the assigned range: `database/migrations/2026_08_06_900000_create_ai_annotation_tables.php` and `database/migrations/2026_08_06_900100_add_side_effect_snapshot_to_ai_annotation_operations.php`.
- Owned tests exist under `tests/Component/AiAnnotations` and `tests/Feature/AiAnnotations`; no `tests/Unit/AiAnnotations` directory was required by the validated milestone acceptance evidence because the coverage is feature/component focused.
- No owned screen references exist; visual reference/capture comparison is not applicable.

## Device evidence

No device evidence files were present for this slice. Recorded as no device evidence.

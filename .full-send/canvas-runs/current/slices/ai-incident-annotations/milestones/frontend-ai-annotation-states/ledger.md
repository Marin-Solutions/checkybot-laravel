# Frontend AI annotation states ledger

## Scope

Implemented only `frontend-ai-annotation-states`. No shared dashboard/product source was changed: the milestone adds contract tests under `tests/Component/AiAnnotations` and exercises the existing `MonitorDetail`/`Annotations` UI through the canonical Expo web runtime. There are no owned screen references, so reference-capture comparison is not applicable.

## Acceptance evidence

- **AC-ai-incident-annotations-11** — `AnnotationStates.test.tsx` renders a contract-valid non-null `root_cause` through the existing `MonitorDetail` annotation section. It asserts a single readable value, `whitespace-pre-wrap` wrapping, no forced no-wrap or injected line breaks, and no rendering of deliberately supplied provider, model, prompt, raw snippet, credential, token, cost, or failure metadata.
- **AC-ai-incident-annotations-12** — the same component suite covers never-enabled, disabled, skipped, failed, and not-yet-completed states as the contract-equivalent nullable slot. Every case asserts the explicit labelled `Unavailable` state, no status/progress/alert fallback, and unchanged timeline, incident-group, and provider-neutral sibling annotation content.
- **AC-ai-incident-annotations-13** — `AiAnnotationRuntime.spec.ts` authenticates through `/__harness/web-dashboard/authenticate/{project}`, confirms an opted-out server incident with three real `POST /api/v2/agent-reports` calls, runs the registered foundation relay, and verifies the existing monitor-detail UI shows `Root Cause — Unavailable`. It then uses browser-session GET/PUT settings requests (only `enabled` and `version`, no project selector), confirms a second server incident through the agent API, invokes the registered relay, waits for the real database queue worker and harness receipt, and verifies the redacted root-cause paragraph in the shared UI. The receipt's notification/lifecycle count snapshot is identical before and after AI processing.

## Runtime artifacts

Latest evidence index: `build/ai-annotation-runtime/evidence.json`

Final run `469319d2-a108-4564-a036-b128efd0c773`:

- Screenshot: `build/ai-annotation-runtime/469319d2-a108-4564-a036-b128efd0c773/ai-annotation-monitor-detail.png`
- Trace: `build/ai-annotation-runtime/469319d2-a108-4564-a036-b128efd0c773/ai-annotation-trace.zip`
- Browser journey log: `build/ai-annotation-runtime/469319d2-a108-4564-a036-b128efd0c773/playwright.log`
- Backend log: `build/ai-annotation-runtime/469319d2-a108-4564-a036-b128efd0c773/backend.log`
- Real worker log: `build/ai-annotation-runtime/469319d2-a108-4564-a036-b128efd0c773/worker.log`
- Frontend dev-server log: `build/ai-annotation-runtime/469319d2-a108-4564-a036-b128efd0c773/frontend.log`
- Registered relay log: `build/ai-annotation-runtime/469319d2-a108-4564-a036-b128efd0c773/relay.log`

## Verification results

| Command | Exit | Result |
| --- | ---: | --- |
| `./node_modules/.bin/jest --config tests/Component/AiAnnotations/jest.config.cjs --runInBand` | 0 | 6 tests passed (present state plus five absent reasons) |
| `node tests/Component/AiAnnotations/runtime-stage.mjs prepare` | 0 | Created run-scoped workspace runtime `469319d2-a108-4564-a036-b128efd0c773` |
| `node tests/Component/AiAnnotations/runtime-stage.mjs start` | 0 | Printed effective `sqlite` config pointing to the run directory, migrated that throwaway DB, and started backend, real database worker, and Expo web dev server |
| `./node_modules/.bin/playwright test --config tests/Component/AiAnnotations/playwright.config.ts` | 0 | 1 canonical full-runtime journey passed in 6.3s |
| `node tests/Component/AiAnnotations/runtime-stage.mjs verify-evidence` | 0 | Screenshot, trace, logs, both UI states, and no-notification proof validated |
| `node tests/Component/AiAnnotations/runtime-stage.mjs stop` | 0 | Stopped only marker-verified processes belonging to the runtime |
| `vendor/bin/pint --test tests/Component/AiAnnotations/Runtime/seed.php` | 0 | PHP runtime seed style passed |

Database safety: before the only migration command, `config:show database` printed default `sqlite` and the exact SQLite file under `build/ai-annotation-runtime/<run-id>/database.sqlite`. No destructive migration command was run.

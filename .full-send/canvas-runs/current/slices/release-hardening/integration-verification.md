# Integration verification — release-hardening

Spec: `.full-send/canvas-runs/current/slices/release-hardening/slice-spec.json`
Review task UUID: `1d52423b-cfc3-42de-859b-5374bffacf2c`
Verdict: `slice_approved`

## Commands executed during integration review

| Command | Result | Evidence |
|---|---|---|
| `./vendor/bin/pest tests/Feature/ReleaseHardening --compact` | PASS | 7 tests, 188 assertions, duration 20.49s. |
| `node tests/Component/ReleaseHardening/runtime-stage.mjs prepare` | PASS | Created run `5b50b870-7b9a-4524-b7d1-a96edeb00fd4` with run-scoped SQLite path under `build/release-hardening-runtime/`. |
| `node tests/Component/ReleaseHardening/runtime-stage.mjs build` | PASS | Expo web export produced `index.html` in the run `web-build` directory. |
| `node tests/Component/ReleaseHardening/runtime-stage.mjs component` | PASS | 2 suites / 12 component tests passed. |
| `node tests/Component/ReleaseHardening/runtime-stage.mjs start` | PASS | Printed effective SQLite config; ran non-destructive migrations against the workspace-local run DB; launched HTTP backend, built static web surface, and queue worker PID 695025. |
| `node tests/Component/ReleaseHardening/runtime-stage.mjs playwright` | PASS | Canonical Playwright journey passed: `1 passed (23.3s)`. |
| `node tests/Component/ReleaseHardening/runtime-stage.mjs stop` | PASS | Cleanup proof recorded backend=false, worker=false, frontend=false. |
| `node tests/Component/ReleaseHardening/runtime-stage.mjs finalize` | PASS | Wrote handover manifest and secret scan passed with 0 matches. |

Latest generated manifest: `.full-send/canvas-runs/current/handover-checklists/evidence/frontend-final-states-runtime-proof/5b50b870-7b9a-4524-b7d1-a96edeb00fd4/handover-evidence-manifest.json`.

## Queue, surface, and database evidence

- Built surface requirement satisfied: `runtime-stage.mjs build` exported an Expo web artifact, and Playwright exercised the built static surface at `http://127.0.0.1:33869`.
- Queue worker requirement satisfied: worker log `.full-send/canvas-runs/current/handover-checklists/evidence/frontend-final-states-runtime-proof/5b50b870-7b9a-4524-b7d1-a96edeb00fd4/worker.log` contains `[stage=worker-start]` and processed jobs including `ProcessMonitorResult`, `EmitIncidentIntent`, `ProcessNotificationIntent`, `DeliverExpoPush`, and `DeliverLegacyWebhook`.
- Database safety satisfied: no destructive database command was run. The effective config printed `database.default` as `sqlite` and the active SQLite file as `/home/ploi/workspaces/agent-canvas-b1631f73-b32f-4463-ae9b-0633a2a40625-checkybot-laravel/build/release-hardening-runtime/5b50b870-7b9a-4524-b7d1-a96edeb00fd4/database.sqlite` before non-destructive migrations.

## API contract integration notes

- `POST /__harness/alerting/results` was exercised over real HTTP for pull blip, push critical samples, duplicate replay, and returned expected 202/200 statuses.
- `GET /__harness/alerting/receipts/{operation_id}` was exercised over real HTTP and returned processed receipt shapes with transitions, incident groups, and notification intents matching the contract.
- `GET /__harness/push/receipts/{operation_id}` was exercised over real HTTP and returned 404 for no-intent blip operations plus processed Expo/legacy webhook receipt data for the critical intent.
- `GET /api/status-summary` was exercised over real HTTP with bearer token and returned 200 with `servers.down=2`, `updated_at`, and `stale=false`; the surfaced failure path retained cached data.
- `PushReliabilityReadModel::forProject(ProjectIdentity)` was covered by the backend Pest proving-gate tests.
- `GET {CHECKYBOT_WATCHDOG_URL}` and `POST /api/v2/agent-reports` are not owned runtime changes in this slice; the release/fleet contract tests verify the required handover evidence and fail-closed checklist semantics without altering those product contracts.

## Acceptance criteria evidence map

| ID | Result | Evidence |
|---|---|---|
| AC-release-hardening-1 | PASS | Backend Pest re-run passed. `ReleaseGateRuntimeTest.php` posts failure/success pull observations through real harness HTTP with real queue runtime and asserts no incident groups, no notification intents, and 404 push receipts. |
| AC-release-hardening-2 | PASS | Backend Pest re-run passed. Multi-monitor critical/recovery test proves one grouped incident and one recovery sharing a thread, plus one accepted Expo and one accepted legacy webhook per intent after replay dedupe. |
| AC-release-hardening-3 | PASS | Backend Pest re-run passed. Clock-controlled tests use `PushReliabilityReadModel` and retirement checklist contract to block missing/stale/failed/dependent watchdog, short windows, and failed/missing pairs; only current independent watchdog plus 28 complete days signs. |
| AC-release-hardening-4 | PASS | Backend Pest re-run passed. Fleet-readiness mutation tests fail missing server inventory, wrong schema/interval/version, prerequisites, unapproved MySQL exception, canary, scoped-token, rollback owner, and heartbeat gaps. |
| AC-release-hardening-5 | PASS | Backend Pest re-run passed. Documentation/template/secret-scan tests verify executable commands, thresholds, evidence paths, owners/timestamps, expiry, rollback triggers, Telegram generic-webhook mapping, fleet order, and no-secret rejection. |
| AC-release-hardening-6 | PASS | Component stage re-run passed 12/12. `StatusSurfaceStates.test.tsx` imports production `StatusScreen` and proves loading, explicit all-healthy/empty, expected problem rows only, and cached offline state with last-sync age. |
| AC-release-hardening-7 | PASS | Component stage re-run passed 12/12. `WidgetFreshnessFailClosed.test.ts` imports production widget provider/view model and proves age visibility, 900-second boundary, stale/error/auth fail-closed non-green treatments. |
| AC-release-hardening-8 | PASS | Playwright runtime re-run passed against built static surface, real HTTP backend, and real queue worker. Manifest snapshots show 20-second blip with zero intents and 404 push receipts; confirmed critical group with deduped Expo/legacy receipts; `GET /api/status-summary` updates counts; forced API failure retains cached stale/error state. |
| AC-release-hardening-9 | PASS | Finalized manifest for run `5b50b870-7b9a-4524-b7d1-a96edeb00fd4` includes run ID, commit, timestamps, commands, endpoint URLs, SQLite path, queue-worker proof, response snapshots, component/Playwright summaries, screenshots, trace/log paths, watchdog/proving/fleet references, secret scan, and cleanup proof. |

## Device evidence

No device verification file is present for this slice. Recorded as **no device evidence** and not treated as a blocker.

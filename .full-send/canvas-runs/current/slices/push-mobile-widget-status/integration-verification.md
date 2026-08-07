# Integration verification — push-mobile-widget-status

Spec reviewed: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/slice-spec.json`  
Review task: `b1ba2008-5eb4-4765-b4d9-c6fc28ad6f46`

## Safety

No destructive database command was run. Runtime verification used the harness-created run-scoped SQLite database at `build/harness-runs/91516bb0-efee-4f7f-9292-09ff20b5ae92/database.sqlite`. No host service control, Redis/database shutdown, Supervisor control, or host package installation was attempted.

## Commands rerun

| Command | Result |
| --- | --- |
| `npm --prefix mobile run typecheck` | PASS |
| `npm --prefix mobile test -- --watch=false` | PASS — 7 suites, 37 tests, 1 snapshot |
| `npm --prefix mobile run widget:config:verify` | PASS — isolated Expo iOS prebuild verifier found one medium iOS extension and no Android widget module |
| `./vendor/bin/pest tests/Feature/PushNotifications --compact` | PASS — 8 tests, 146 assertions |
| `./vendor/bin/pest tests/Feature/PushNotifications/AlertingToPushEndToEndTest.php --compact` | PASS — 1 test, 19 assertions |
| `npm --prefix mobile run build:ios` | PASS — Expo iOS export produced `mobile/build/ios` |
| `npm --prefix mobile run build:android` | PASS — Expo Android export produced `mobile/build/android` |
| `npm --prefix mobile run build:web:harness` | PASS — built web harness output at `build/harness-fixture` |
| Runtime: `node mobile/e2e/runtime.mjs backend-start`; `seed-device`; `frontend-start`; `cd mobile && npx playwright test --config e2e/playwright.config.ts`; `evidence`; `stop` | PASS — Playwright 1/1 passed, evidence verified, scoped app/worker/fixture processes stopped |
| `./vendor/bin/phpstan analyse --no-progress` | PASS |
| `./vendor/bin/pint --test` | PASS |

## Full-runtime evidence

Latest runtime run: `91516bb0-efee-4f7f-9292-09ff20b5ae92`

Evidence files:

- `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/frontend-expo-status-app/evidence/runtime-stages.json`
- `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/frontend-expo-status-app/evidence/runtime-journey.json`
- `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/frontend-expo-status-app/evidence/runtime-cleanup.json`
- `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/frontend-expo-status-app/evidence/runtime-status-offline.png`
- `build/harness-runs/91516bb0-efee-4f7f-9292-09ff20b5ae92/worker.log`

Key observations:

- `runtime-stages.json` records `database: run-scoped SQLite`, `queue_worker: real database queue worker`, and `worker_start_observed: true`.
- `worker.log` shows real `queue:work` execution of `ProcessMonitorResult`, `ProcessIncidentTransition`, `EmitIncidentIntent`, `DeliverFoundationEvent`, `ProcessNotificationIntent`, `DeliverExpoPush`, and `DeliverLegacyWebhook`.
- `runtime-journey.json` records three real `POST /__harness/alerting/results` 202 responses, grouped critical intent `1969a50e-eac6-5529-9a48-a6705e575746`, `relay_exit: 0`, real receipt API response with `listener_status: processed`, accepted Expo delivery, accepted legacy webhook, `reliability_recorded: true`, and `refresh_widget: true`.
- `runtime-journey.json` records authenticated `GET /api/status-summary` HTTP 200, loading observed, problem observed, deep-link filter observed, offline cache observed, and `browser_console_secret_free: true`.
- `runtime-cleanup.json` records stopped fixture, worker, and app roles with `owned_children_alive: 0`.

## Device evidence

No `device/device-review.md` or `device/build-failure.md` exists under `.full-send/canvas-runs/current/slices/push-mobile-widget-status/`; recorded as no device evidence.

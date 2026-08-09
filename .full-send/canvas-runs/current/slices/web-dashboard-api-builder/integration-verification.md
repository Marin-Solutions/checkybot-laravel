# Integration verification — web-dashboard-api-builder

## Commands and runtime evidence

| Verification | Result | Evidence |
|---|---:|---|
| Read slice spec and all milestone review artifacts | Pass | `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/slice-spec.json`; milestone `review.md` files cover AC-web-dashboard-api-builder-1 through -16. |
| Checked for device evidence files | No device evidence | No files found under `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/device/`. |
| `npx expo export tests/Feature/WebDashboard/RuntimeApp --platform web --output-dir /home/ploi/workspaces/agent-canvas-b1631f73-b32f-4463-ae9b-0633a2a40625-checkybot-laravel/build/integration-review-web-dashboard-built-surface --clear` | Pass | Built production-shaped web output: `build/integration-review-web-dashboard-built-surface/index.html`, `_expo/static/js/web/index-3a648b8eb9976af7cd1e68065b211da9.js`, `metadata.json`. Logged in `build/integration-review-web-dashboard-built-surface.log`. |
| `node tests/Feature/WebDashboard/Runtime/runtime-stage.mjs prepare` | Pass | Prepared runtime `7acdd91a-0d1f-4d1f-a76c-e12e1d6289c1` with SQLite at `build/web-dashboard-api-runtime/7acdd91a-0d1f-4d1f-a76c-e12e1d6289c1/database.sqlite`. |
| `node tests/Feature/WebDashboard/Runtime/runtime-stage.mjs start` | Pass | Printed effective DB config before non-destructive `migrate`: `default = sqlite`; SQLite database path was inside this workspace. Started Laravel backend, upstream fixture, and real database queue worker `worker_pid=594728`. |
| Replaced only the owned dev Metro process with a static server serving the built output on the same runtime frontend origin | Pass | Static server log `build/web-dashboard-api-runtime/7acdd91a-0d1f-4d1f-a76c-e12e1d6289c1/static.log` shows `GET /` and bundle requests returning 200 from the built output. The script refused to stop any process unless `/proc/<pid>/environ` contained the runtime id. |
| `./node_modules/.bin/playwright test --config tests/Feature/WebDashboard/playwright.config.ts` against built static surface | Pass | 1/1 canonical journey passed. Evidence JSON: `build/web-dashboard-api-runtime/7acdd91a-0d1f-4d1f-a76c-e12e1d6289c1/evidence.json`. |
| `node tests/Feature/WebDashboard/Runtime/runtime-stage.mjs stop` | Pass | Stopped only PID groups matching the unique runtime id. |

## Built-surface runtime checks performed

The integration Playwright pass exercised the built web bundle, not a dev server, while the real queue worker was running. It verified:

- three real alerting result POSTs to `/__harness/alerting/results`;
- processing through the real `queue:work database` worker;
- registered foundation relay execution;
- harness web authentication through HTTP;
- overview/problem/detail Inertia flow;
- maintenance banner;
- live sample fetch through the real sample route;
- JSON path selection and assertion save through the real save route;
- masked header preserve payload without secret value;
- upstream-auth manual fallback.

`worker.log` for runtime `7acdd91a-0d1f-4d1f-a76c-e12e1d6289c1` shows `ProcessMonitorResult`, `ProcessIncidentTransition`, and `DeliverFoundationEvent` jobs running and completing under `queue:work`.

## Database and lane safety

No destructive migration/database command was run. The only migration command was non-destructive `migrate` after the runtime printed effective configuration showing `database.default = sqlite` and the active SQLite database inside the workspace-local run directory. No database/Redis server shutdown, Supervisor control, host critical service operation, or host-level package installation was performed.

## Notes

An initial integration attempt used a relative Expo export output path and therefore served the wrong directory, causing a 404 before the built app loaded. That attempt was cleaned up, then rerun with an absolute output path and passed. The passing run above is the evidence used for this review.

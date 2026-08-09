# Frontend Expo/browser harness milestone ledger

- Milestone: `frontend-expo-browser-harness`
- Spec section: `frontend-expo-browser-harness`
- Scope/manifest target: frontend / `mobile`
- Review round: 2 (manifest schema repair)
- Commit baseline: `3ed7c25`
- Final evidence run: `9f7068cc-399d-4e14-9d31-b755e7f5737e`
- Evidence completed: `2026-08-07T10:42:36Z`

## Owned implementation

- Added an e2e-only Expo web fixture under `e2e/harness-fixture/` using React Native primitives; no product UI was introduced.
- Added locked npm dependencies and canonical build, component, browser, lifecycle, and full-integration scripts in `package.json` / `package-lock.json`.
- Added a loopback-only static fixture server with same-origin proxying to the three declared Laravel harness endpoints.
- Added run-owned frontend start/stop lifecycle handling in `scripts/runtime/frontend`.
- Added five mocked contract-state component tests and a headless Playwright real-HTTP/real-worker queue journey.
- Added the reusable integration proof template and an orchestrator that records commands, timestamps, URLs, queue evidence, logs, screenshot, trace, and child cleanup.

## Safety and runtime isolation

- No Artisan migration command or destructive database command was run.
- The canonical integration script explicitly configures backend state as SQLite at `build/harness-runs/<run-id>/database.sqlite`; backend validation rejects any other path/driver before initialization.
- Both servers bind to `127.0.0.1`. The fixture lifecycle accepts only a recorded UUID backend run directory and loopback backend URL.
- Frontend stop checks PID ownership using run ID and run directory before signaling, removes the fixture PID record, and integration snapshots all three PIDs before cleanup to prove fixture, worker, and app children are no longer owned/live.

## Acceptance criteria evidence

### AC-test-harness-5 — PASS

- Clean locked install: `npm ci --no-audit --no-fund` exited 0 (1,048 packages from `package-lock.json`).
- `npm run harness:frontend:build` exited 0 and exported `build/harness-fixture/index.html` plus the Metro web bundle.
- Final integration served that generated build at `http://127.0.0.1:38237`, then `scripts/runtime/frontend stop` exited 0.
- Post-run evidence: `fixture.pid` was removed; recorded worker/app PIDs were stopped. The orchestrator's PID snapshot check also exited 0 before proof generation.

### AC-test-harness-6 — PASS

- `npm run harness:test:component` exited 0: 5 tests passed.
- Tests cover backend-starting (`app=starting`, `queue=starting`), ready, POST queued, GET queued→processed with non-null `processed_at`, and failed readiness/API-error.
- Mock values use the declared UUID and ISO-8601 fields and the contract's exact readiness/probe state unions.

### AC-test-harness-7 — PASS

- Playwright opened the built Expo fixture, observed `Backend ready`, issued the real POST, visibly observed `Queue probe queued`, polled real GET requests while the database queue worker ran, then observed `Queue probe processed`.
- Final probe: `36086edf-6679-4567-8d77-20863bdfb453`; POST 202; processed GET 200; non-null processed timestamp is recorded in `probe-evidence.json`.
- Evidence: `build/harness-runs/9f7068cc-399d-4e14-9d31-b755e7f5737e/probe-evidence.json`, `processed.png`, and Playwright `trace.zip` under its `playwright/` directory.

### AC-test-harness-8 — PASS

- `npm run harness:integration` exited 0 only after backend + real queue worker startup, Expo build, all component tests, fixture startup, Playwright, frontend stop, backend stop, and owned-PID cleanup checks passed.
- Completed proof derived from the configured template: `build/harness-runs/9f7068cc-399d-4e14-9d31-b755e7f5737e/integration-proof.md`.
- The proof contains run ID, commit, per-command timestamps/exits, backend/frontend endpoint URLs, queue evidence, PASS summary, and log/screenshot/trace paths.

## Verification results

| Command | Exit | Result |
|---|---:|---|
| `npm ci --no-audit --no-fund` | 0 | Clean locked dependency install |
| `npm run harness:frontend:build` | 0 | Canonical Expo web export generated fixture |
| `npm run harness:test:component` | 0 | 5/5 contract-state tests passed |
| `npm run harness:integration` (clean-install round) | 0 | Full stack, 5 component tests, 1 Playwright test, proof and cleanup passed; run `2c640de1-d35d-4bee-a488-20dba97bacc7` |
| `npm run harness:integration` (final stability round) | 0 | Full stack, 5 component tests, 1 Playwright test, proof and cleanup passed; run `9f7068cc-399d-4e14-9d31-b755e7f5737e` |
| TypeScript no-emit + Bash/Node syntax + `git diff --check` | 0 | Static and syntax checks passed |

## Resolved diagnostics

Initial local rounds identified an unpinned React test renderer, an obsolete matcher setup path, and Playwright/browser-revision mismatch. Dependencies are now exact where runtime coupling matters (`react-test-renderer` 19.1.0 and Playwright 1.56.1), the lockfile was regenerated, and two subsequent full integration rounds passed with complete cleanup.

Repair round 1 identified invalid free-form artifact labels in the verification manifest. The labels were mechanically remapped to the allowed enum values `test_results`, `criteria_evidence_map`, `browser_screenshot`, and `browser_network_summary`; targets and previously executed verification commands remain unchanged.

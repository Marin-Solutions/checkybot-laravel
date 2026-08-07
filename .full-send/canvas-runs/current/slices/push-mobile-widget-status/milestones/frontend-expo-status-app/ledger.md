# Frontend Expo status app — implementation ledger

Milestone: `frontend-expo-status-app`  
Scope: `mobile`, `tests/Component/MobileStatus`, and this milestone's configured artifacts only.

## Delivered

- Added a locked Expo SDK 54 / Expo Router product shell in `mobile/`, buildable for iOS, Android, and the canonical web harness.
- Generated strict mobile runtime contracts from `packages/contracts/monitor-foundation.schema.json`; the generated parser validates all nine counts, lifecycle states, severity, freshness, and monitor filters.
- Added one typed `CheckybotApiClient` for authenticated status reads and push-device registration/deactivation.
- Added Expo SecureStore and native notification adapters, stable installation lifecycle, token rotation, permission gating, and sign-out deactivation without AsyncStorage or product logging.
- Added deterministic loading, all-healthy, non-healthy-only problem, stale, and offline/cached status states.
- Added notification/widget deep-link validation with exact project/group/unique-monitor filters and an Android-safe iOS widget module gate.
- Added component/contract/router/lifecycle tests and a real-worker Playwright runtime journey with redacted evidence.

## Acceptance evidence

### AC-push-mobile-widget-status-6

- `npm ci --prefix mobile --ignore-scripts` — exit 0; lockfile installed 1,153 packages.
- `npm --prefix mobile run contracts:check` — exit 0; generated contracts current.
- `npm --prefix mobile run typecheck` — exit 0.
- `npm --prefix mobile run build:ios` — exit 0; Expo Router iOS bundle exported.
- `npm --prefix mobile run build:android` — exit 0; Expo Router Android bundle exported.
- `ContractParsing.test.ts` proves accepted shared states/severities/filters, the exact 900-second freshness boundary, and rejection of unknown lifecycle state, missing/extra count cells, and negative counts.

### AC-push-mobile-widget-status-7

- `npm --prefix mobile test -- --watch=false` — exit 0; 4 suites / 16 tests passed.
- `StatusScreen.test.tsx` proves progress before first response, explicit all-healthy copy, omission of healthy rows in problem state, and cached problem counts plus `Last synced 5m ago` after refresh failure.

### AC-push-mobile-widget-status-8

- `DeviceLifecycle.test.ts` proves granted and provisional POST registration, one installation across token rotation, no request when denied, owned-device DELETE on sign-out, secure-vault-only credential persistence, and no token/credential logs.
- Product source contains no AsyncStorage and no product `console.*` calls.

### AC-push-mobile-widget-status-9

- `DeepLinks.test.ts` proves incident and recovery notification routing with exact project/group/unique monitor UUIDs, widget warn/down routing, malformed/duplicate/foreign-project rejection, and no iOS bridge loader call on Android or web.

### AC-push-mobile-widget-status-10

Staged commands, all exit 0:

1. `npm --prefix mobile run build:web:harness`
2. `node mobile/e2e/runtime.mjs backend-start`
3. `node mobile/e2e/runtime.mjs seed-device`
4. `node mobile/e2e/runtime.mjs frontend-start`
5. `cd mobile && npx playwright test --config e2e/playwright.config.ts`
6. `node mobile/e2e/runtime.mjs evidence`
7. `node mobile/e2e/runtime.mjs stop`

Runtime run `7e95a92c-0799-4d5f-89af-692d067d05c5` used a guarded run-scoped SQLite file and a real database queue worker. Three real `POST /__harness/alerting/results` requests returned 202, produced one grouped critical intent, relayed through the registered outbox consumer, and yielded an accepted redacted Expo receipt plus accepted legacy proving receipt. The browser made an authenticated real `GET /api/status-summary` (200), observed loading then the problem screen, opened the exact notification filter, and retained `servers.down=1` and last-synced age during a forced 503. Scoped cleanup reported zero owned children alive.

Evidence:

- `evidence/runtime-journey.json`
- `evidence/runtime-stages.json`
- `evidence/runtime-cleanup.json`
- `evidence/runtime-status-offline.png`

## Database safety

No destructive migration/database command was run. The runtime launcher accepted only `sqlite` at `<workspace>/build/harness-runs/<run UUID>/database.sqlite`, emitted its database verification stage before ordinary migrations, and refused any path outside that run directory.

## Notes

- Existing mobile-user authentication and ProjectApiToken provisioning remain external prerequisites as declared by the slice contract.
- Root `.env.example` was already untracked and was not modified by this milestone.

# Integration verification — laravel-sdk-monitor-definitions

## Inputs reviewed

- Spec: `.full-send/canvas-runs/current/slices/laravel-sdk-monitor-definitions/slice-spec.json`
- Milestone reviews and ledgers for:
  - `backend-sdk-definition-contracts`
  - `backend-sdk-sync-compatibility`
- Device evidence: no device files present.

## Commands run during integration review

| Command | Result |
|---|---|
| `./vendor/bin/pest tests/Unit/Checks tests/Unit/CheckRegistryTest.php tests/Unit/ConfigValidatorTest.php tests/Unit/ConfigTest.php tests/Unit/CheckybotClientTest.php tests/Unit/ServiceProviderTest.php tests/Feature/FluentApiSyncTest.php tests/Feature/SyncCommandTest.php tests/Feature/ServiceProviderTest.php tests/Unit/MonitoringFoundation/ContractParityTest.php tests/Feature/MonitoringFoundation/FoundationHarnessSeamsTest.php --compact` | PASS — 212 tests, 684 assertions |
| `node packages/contracts/scripts/verify-fixtures.mjs` | PASS — 17 shared JSON-schema/TypeScript contract fixtures verified |
| `node packages/contracts/scripts/generate-types.mjs --check` | PASS — generated TypeScript current |
| `./vendor/bin/phpstan analyse --no-progress` | PASS — no errors |
| `git diff --check` | PASS |
| `node build/sdk-sync-runtime/stage-runtime.mjs start && npx playwright test --config build/sdk-sync-runtime/playwright.config.ts; status=$?; node build/sdk-sync-runtime/stage-runtime.mjs stop; exit $status` | PASS — harness start printed run-scoped SQLite database `build/harness-runs/085139df-49d6-4b26-be62-f141786891a6/database.sqlite`; Playwright 1/1 passed; harness stopped |
| `./vendor/bin/pest --compact` | PASS — 324 tests, 2001 assertions |

## Runtime seam evidence

`build/sdk-sync-runtime/evidence.json` from run `085139df-49d6-4b26-be62-f141786891a6` records:

- Real SDK request: `POST /api/v1/projects/22222222-2222-4222-8222-222222222222/checks/sync` with `Accept: application/json`, JSON content type, authenticated bearer presence recorded as boolean, and body containing `contract_version: check-sync.v1` plus all seven canonical arrays.
- Array coverage: `uptime`, `ssl`, `api`, `dead_links`, `open_graph`, `domain_expiry`, and `response_time_budget`; each had one runtime definition.
- API assertion coverage: ordered status, latency, and JSON-path assertion metadata with stable `sort_order` and `is_active` values.
- Harness event submission: browser `POST /__harness/monitor-foundation/events` returned 202 for `contract.check_sync.probed`.
- Relay: registered `checkybot:foundation-relay` command exited 0 and relayed one event.
- Queue worker: evidence states a real database queue worker was started by `scripts/runtime/backend`; Playwright observed `/__harness/ready` with queue ready.
- Receipt: `GET /__harness/monitor-foundation/receipts/{operation_id}` returned 200 with status `delivered`, consumer `sdk`, `schema_valid: true`, `contract_version: check-sync.v1`, and all seven check types.

## Safety notes

No destructive Laravel migration/database commands were run. Harness cleanup stopped only the run-scoped harness backend started by this review. No Supervisor, host database/Redis server, or critical host service controls were used.

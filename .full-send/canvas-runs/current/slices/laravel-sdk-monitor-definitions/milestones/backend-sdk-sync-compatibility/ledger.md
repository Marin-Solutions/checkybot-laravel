# Backend SDK sync compatibility ledger

Milestone: `backend-sdk-sync-compatibility`  
Spec section: `backend-sdk-sync-compatibility`

## Implemented acceptance evidence

- **AC-laravel-sdk-monitor-definitions-6** — `CheckybotClient` now enforces HTTPS outside the loopback harness, disables sync redirects/replays, URL-encodes opaque project IDs, sends explicit authenticated JSON, accepts 200/202, and emits bounded redacted exceptions/logs. Focused transport tests assert one exact canonical seven-array POST, all declared failures, and a fixed secret corpus.
- **AC-laravel-sdk-monitor-definitions-7** — `checkybot:sync` counts and dry-run-labels all seven canonical types using only safe name/URL/interval fields. Summary output always renders seven types and normalizes canonical and legacy aliases plus absent operation counts to zero. Registry/config and secret-masking feature tests cover both paths.
- **AC-laravel-sdk-monitor-definitions-8** — README, CHANGELOG, facade docblocks, published config, and fluent stub now include domain-expiry, p95 budget, API status/latency/body examples, `check-sync.v1` migration compatibility, and the authenticated-HTTPS/downstream-encryption/masked-output boundary. `tests/Unit/ConfigTest.php` enforces the documentation contract.
- **AC-laravel-sdk-monitor-definitions-9** — Fluent and config commands produce identical exact HTTP bodies with all current optional fields. Tests submit both bodies to the foundation `ContractValidator` and prove missing arrays (including both new arrays missing together), unknown versions, malformed definitions, and unknown package top-level fields fail. The event endpoint regression proves the invalid body returns 422 before enqueueing. The shared JSON schema, generated TypeScript, and foundation fixtures now require all seven arrays.
- **AC-laravel-sdk-monitor-definitions-10** — Harness-only loopback sync capture is registered from `src` only in `testing|harness`. Playwright repair run `c925c8a8-6442-49fb-83cd-0f27a76f5362` used real fluent APIs for seven types, a real `CheckybotClient` POST, browser submission to the foundation event endpoint, the registered relay command, and the real database queue worker. It observed the seven-type SDK schema-valid receipt. Evidence: `build/sdk-sync-runtime/evidence.json`; Playwright trace: `build/sdk-sync-runtime/playwright-results/`.

## Review round 1 correction

- Removed the five-array compatibility bypass identified by review. `ContractValidator::validateCheckSync()` now requires every canonical array in every case.
- Added direct and HTTP event-boundary regressions for a payload missing both `domain_expiry` and `response_time_budget`; the foundation harness also asserts no second event was enqueued.
- Updated the stale foundation schema/fixture/generated type seam to seven arrays so contract parity tests remain source-of-truth checks rather than permitting the invalid legacy profile.

## Compatibility and safety notes

- Production never registers the capture route; HTTP transport is accepted only for loopback hosts in `testing|harness`.
- Capture evidence records authentication as a boolean and never persists the bearer key.
- No migrations were added. Runtime verification used the existing guarded harness, which printed `connection=sqlite` and a database path inside its UUID run directory before migration. The run-scoped worker/server were stopped by the ownership-checking harness stop stage.
- The pre-existing untracked `.env.example` was not modified.

## Verification results

| Command | Result |
|---|---|
| `./vendor/bin/pest tests/Unit/CheckybotClientTest.php tests/Feature/SyncCommandTest.php tests/Feature/FluentApiSyncTest.php tests/Unit/ConfigTest.php tests/Unit/MonitoringFoundation/ContractParityTest.php tests/Feature/MonitoringFoundation/FoundationHarnessSeamsTest.php --compact` | exit 0 — 78 tests, 441 assertions (repair round) |
| `node packages/contracts/scripts/verify-fixtures.mjs` | exit 0 — 17 shared schema fixtures verified |
| `node packages/contracts/scripts/generate-types.mjs --check` | exit 0 — generated TypeScript current |
| `./vendor/bin/pest --compact` | implementation round exit 0 — 324 tests, 1992 assertions; review round observed one unrelated SQLite lock flake that passed alone |
| `./vendor/bin/phpstan analyse --no-progress` | exit 0 — no errors (repair round) |
| `node build/sdk-sync-runtime/stage-runtime.mjs start` | exit 0 — guarded run-scoped SQLite runtime and real queue worker ready |
| `npx playwright test --config build/sdk-sync-runtime/playwright.config.ts` | exit 0 — 1 canonical seam test passed |
| `node build/sdk-sync-runtime/stage-runtime.mjs stop` | exit 0 — owned runtime processes stopped |
| `git diff --check` | exit 0 |

# Backend SDK sync compatibility review

Review round: 2 (prior review artifact recorded round 1 with AC-9 changes requested; ledger records the round-1 correction).

Verdict: approved

## Acceptance criteria

| Criterion | Result | Evidence |
|---|---:|---|
| AC-laravel-sdk-monitor-definitions-6 | PASS | Re-ran focused manifest Pest suite: `./vendor/bin/pest tests/Unit/CheckybotClientTest.php tests/Feature/SyncCommandTest.php tests/Feature/FluentApiSyncTest.php tests/Unit/ConfigTest.php tests/Unit/MonitoringFoundation/ContractParityTest.php tests/Feature/MonitoringFoundation/FoundationHarnessSeamsTest.php --compact` passed with 78 tests / 441 assertions. `tests/Unit/CheckybotClientTest.php` proves one authenticated JSON POST to `/api/v1/projects/{encoded_project_id}/checks/sync`, `contract_version=check-sync.v1`, all seven arrays, 200 and loopback 202 acceptance, 401/403/422/transport failure mapping to `CheckybotSyncException`, HTTPS enforcement, and redaction from exception/log surfaces. |
| AC-laravel-sdk-monitor-definitions-7 | PASS | Same focused Pest run passed. `tests/Feature/SyncCommandTest.php` covers registry and config dry-run counts/labels for all seven types, omission of sensitive fields, canonical summary keys, legacy `uptime_checks`/`ssl_checks`/`api_checks`/`link_checks`/`open_graph_checks` aliases, new-type `*_checks` aliases, and absent created/updated/deleted counts normalized to zero. |
| AC-laravel-sdk-monitor-definitions-8 | PASS | Same focused Pest run passed. `tests/Unit/ConfigTest.php` enforces README, CHANGELOG, facade docblock, published config, and stub documentation for fluent/config domain-expiry, p95 response-budget, and API status/latency/body assertion examples; `check-sync.v1`; upgrade compatibility; authenticated HTTPS downstream encryption; and masking from package output. Spot review of those files confirms the documented examples and security boundary are present. |
| AC-laravel-sdk-monitor-definitions-9 | PASS | Focused Pest run passed. `tests/Feature/FluentApiSyncTest.php` validates exact fluent-generated and config-generated HTTP bodies against `ContractValidator`, with all seven arrays and optional fields; `tests/Unit/MonitoringFoundation/ContractParityTest.php` validates 17 shared fixtures; `tests/Feature/MonitoringFoundation/FoundationHarnessSeamsTest.php` verifies the event endpoint rejects malformed check-sync payloads before enqueue. The previous AC-9 failure is corrected: `src/Domain/Monitoring/Foundation/Contracts/ContractValidator.php` now requires all seven canonical arrays with `present`, the shared JSON schema required list includes `domain_expiry` and `response_time_budget`, and regressions cover both new arrays missing. `node packages/contracts/scripts/verify-fixtures.mjs` passed (17 fixtures) and `node packages/contracts/scripts/generate-types.mjs --check` passed. |
| AC-laravel-sdk-monitor-definitions-10 | PASS | Re-ran the canonical full-runtime harness: `node build/sdk-sync-runtime/stage-runtime.mjs start` passed after printing `[stage=database-verified] connection=sqlite database=/home/ploi/workspaces/.../build/harness-runs/e4efd097-ea05-4b29-83f5-0b4dec27005d/database.sqlite` and `[stage=ready] app=ready queue=ready`; `npx playwright test --config build/sdk-sync-runtime/playwright.config.ts` passed (1 test); `node build/sdk-sync-runtime/stage-runtime.mjs stop` passed. `build/sdk-sync-runtime/evidence.json` records the real SDK POST body with all seven arrays, 202 harness response, browser POST to `/__harness/monitor-foundation/events`, registered `checkybot:foundation-relay` command result, and a delivered `sdk` `schema-valid` receipt listing all seven types through the receipt API. The evidence explicitly uses a real database queue worker started by `scripts/runtime/backend`; no validator/processor/job/consumer is invoked directly for the asynchronous seam. |

## Verification commands rerun

- `./vendor/bin/pest tests/Unit/CheckybotClientTest.php tests/Feature/SyncCommandTest.php tests/Feature/FluentApiSyncTest.php tests/Unit/ConfigTest.php tests/Unit/MonitoringFoundation/ContractParityTest.php tests/Feature/MonitoringFoundation/FoundationHarnessSeamsTest.php --compact` — PASS, 78 tests / 441 assertions.
- `node packages/contracts/scripts/verify-fixtures.mjs` — PASS, 17 shared JSON-schema/TypeScript contract fixtures verified.
- `node packages/contracts/scripts/generate-types.mjs --check` — PASS, generated TypeScript current.
- `./vendor/bin/phpstan analyse --no-progress` — PASS, no errors.
- `git diff --check` — PASS.
- `./vendor/bin/pest --compact` — PASS, 324 tests / 2001 assertions.
- `node build/sdk-sync-runtime/stage-runtime.mjs start` — PASS, run-scoped SQLite database and real queue worker ready.
- `npx playwright test --config build/sdk-sync-runtime/playwright.config.ts` — PASS, 1 full-runtime seam test passed.
- `node build/sdk-sync-runtime/stage-runtime.mjs stop` — PASS, owned runtime stopped.

## Notes

No destructive database commands were run. The runtime harness used normal migrations only after verifying a run-scoped SQLite file inside the workspace, and cleanup stopped only harness-owned processes.

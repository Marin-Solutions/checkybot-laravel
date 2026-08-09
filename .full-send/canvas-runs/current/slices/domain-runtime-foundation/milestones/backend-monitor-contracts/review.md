# Review — Shared monitor contracts and persistence

Verdict: **approved**  
Review round: **1** (no prior review artifact was present before this review).

## Acceptance criteria

| Criterion | Result | Evidence |
|---|---|---|
| AC-domain-runtime-foundation-1 | Pass | Re-ran `npm --prefix packages/contracts test && npx tsc --noEmit --skipLibCheck packages/contracts/generated/monitor-foundation.ts` successfully. `tests/Unit/MonitoringFoundation/ContractParityTest.php` uses the shared `packages/contracts/fixtures/contract-cases.json` fixture corpus against the PHP validator, and the package test verifies generated TypeScript freshness plus the same 16 fixtures against `packages/contracts/monitor-foundation.schema.json`. |
| AC-domain-runtime-foundation-2 | Pass | Re-ran `./vendor/bin/pest tests/Unit/MonitoringFoundation tests/Feature/MonitoringFoundation --compact` successfully: 5 tests / 85 assertions. `tests/Feature/MonitoringFoundation/PersistenceTest.php` exercises UUID public IDs, project scopes/isolation, unique current-state identity, enum DB constraints, immutable transition model behavior, operation ordering uniqueness, declared project indexes, and unique outbox operation IDs. Migration `database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php` is inside the declared timestamp range and has a reversible `down()`. |
| AC-domain-runtime-foundation-3 | Pass | `tests/Feature/MonitoringFoundation/FoundationHarnessSeamsTest.php` posts `monitor.transitioned` through `/__harness/monitor-foundation/events`, calls the registered `checkybot:foundation-relay` command, starts an independent `queue:work database --stop-when-empty` process via Testbench, then reads delivered alerting and agent receipts from `/__harness/monitor-foundation/receipts/{operation_id}`. No processor/consumer is invoked directly in the test. |
| AC-domain-runtime-foundation-4 | Pass | The same real harness API + registered relay + real queue worker test observes mobile, widget, and web receipts and asserts each receipt preserves the submitted contract version, monitor identity, state, severity, and filter through the receipt API. |
| AC-domain-runtime-foundation-5 | Pass | `FoundationHarnessSeamsTest.php` posts a `contract.check_sync.probed` payload with uptime, ssl, api, dead_links, and open_graph arrays through the harness API, relays and drains via the real queue worker, then observes an sdk receipt with `effect=schema-valid` and `schema_valid=true`. The test also verifies unknown-version and malformed check payloads return 422 and that the outbox count remains unchanged after invalid submissions. |

## Verification re-run

| Command | Result |
|---|---|
| `npm --prefix packages/contracts test && npx tsc --noEmit --skipLibCheck packages/contracts/generated/monitor-foundation.ts` | Pass — generated TypeScript current; 16 shared fixtures verified. |
| `./vendor/bin/pest tests/Unit/MonitoringFoundation tests/Feature/MonitoringFoundation --compact` | Pass — 5 tests, 85 assertions. |
| `./vendor/bin/phpstan analyse --no-progress` | Pass — no errors. |
| `./vendor/bin/pest --compact` | Pass — 226 tests, 622 assertions. |
| `./vendor/bin/pint --test src/Domain/Monitoring/Foundation src/Models src/CheckybotLaravelServiceProvider.php database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php routes/status-summary.php tests/Unit/MonitoringFoundation tests/Feature/MonitoringFoundation` | Pass. |

## Review notes

- Queue-worker criteria were verified with a real subprocess worker (`queue:work database`), not by direct job/consumer invocation.
- Database safety: no destructive Artisan migration/database commands were run during review. The reviewed tests use PHPUnit/Testbench-controlled SQLite, and the seam tests configure a workspace-local SQLite file under `build/monitor-foundation-tests/` for the worker subprocess.
- Harness routes are guarded to testing/harness environments in `routes/status-summary.php` and additionally require loopback middleware.

No changes requested.

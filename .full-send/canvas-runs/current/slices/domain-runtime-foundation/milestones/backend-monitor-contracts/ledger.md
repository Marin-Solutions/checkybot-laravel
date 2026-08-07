# Backend monitor contracts milestone ledger

Milestone: `backend-monitor-contracts`  
Task: `80bfc119-c800-4c67-af66-d024cec723bd`

## Scope implemented

- Canonical PHP enums, identity value object, strict contract validator, shared JSON Schema, generated TypeScript declarations, generator freshness check, and cross-runtime fixture corpus.
- Monitor state, immutable transition history, outbox event, and project API token persistence in migration timestamp `2026_08_06_000000`.
- UUID model identifiers, project scopes, enum casts/constraints, identity/order/idempotency indexes, and immutable transition model behavior.
- Testing/harness-only loopback event and receipt APIs, transactional event acceptance, registered outbox relay command, queued delivery job, and deterministic fake-consumer receipts.
- Service-provider registration for configuration, migrations, routes, contracts, and relay command.

## Acceptance evidence

| Criterion | Evidence |
|---|---|
| AC-domain-runtime-foundation-1 | `tests/Unit/MonitoringFoundation/ContractParityTest.php` runs `packages/contracts/fixtures/contract-cases.json` through PHP. `npm --prefix packages/contracts test` verifies generated TypeScript freshness and runs the same 16 fixtures against `monitor-foundation.schema.json`; `tsc` type-checks the generated declarations. |
| AC-domain-runtime-foundation-2 | `tests/Feature/MonitoringFoundation/PersistenceTest.php` proves UUIDs, project isolation, identity and operation-order uniqueness, enum checks, immutable history, project indexes, and unique outbox operation IDs. |
| AC-domain-runtime-foundation-3 | `FoundationHarnessSeamsTest.php` posts a typed transition to the registered API, calls the registered relay command, launches an independent real `queue:work database` process, and reads alerting and agent receipts from the receipt API. |
| AC-domain-runtime-foundation-4 | The same endpoint/relay/worker journey verifies mobile, widget, and web receipts preserve contract version, identity, state, severity, and filter. |
| AC-domain-runtime-foundation-5 | The check-sync journey sends all five supported check arrays and observes an SDK `schema-valid` receipt after the real worker; unknown versions and malformed checks return 422 while outbox count remains unchanged. |

## Database safety

No destructive Artisan or database command was run. Persistence tests use PHPUnit-managed `:memory:` SQLite. Worker seam tests create a unique SQLite file under `build/monitor-foundation-tests/`, configure both the test kernel and worker subprocess to that exact workspace-local file, and remove it after each test.

## Verification results

| Command | Exit | Result |
|---|---:|---|
| `npm --prefix packages/contracts test && npx tsc --noEmit --skipLibCheck packages/contracts/generated/monitor-foundation.ts` | 0 | Generated declarations current; 16 shared fixtures passed; TypeScript passed. |
| `./vendor/bin/pest tests/Unit/MonitoringFoundation tests/Feature/MonitoringFoundation --compact` | 0 | 5 tests passed, 85 assertions. |
| `./vendor/bin/pest --compact` | 0 | Full suite: 226 tests passed, 622 assertions. |
| `./vendor/bin/pint --test src/Domain/Monitoring/Foundation src/Models src/CheckybotLaravelServiceProvider.php database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php routes/status-summary.php tests/Unit/MonitoringFoundation tests/Feature/MonitoringFoundation` | 0 | Formatting passed. |
| `./vendor/bin/phpstan analyse --no-progress` | 0 | No errors. |

# Shared monitor contracts and persistence — handoff

Implemented the canonical monitor foundation contracts and persistence layer, including generated frontend contract artifacts and a real relay/queue-worker test seam.

## Key paths

- PHP contracts/runtime: `src/Domain/Monitoring/Foundation/`
- Models: `src/Models/MonitorState.php`, `MonitorTransition.php`, `OutboxEvent.php`, `ProjectApiToken.php`
- Migration: `database/migrations/2026_08_06_000000_create_monitor_foundation_tables.php`
- Shared schema/types/fixtures: `packages/contracts/`
- Harness routes: `routes/status-summary.php`
- Acceptance tests: `tests/Unit/MonitoringFoundation/`, `tests/Feature/MonitoringFoundation/`

The E2E seam tests use a workspace-local SQLite file and an independent Testbench `queue:work database` process. No processor or consumer is directly invoked. All milestone tests, the full Pest suite, TypeScript checks, Pint, and PHPStan pass.

See `ledger.md` for criterion-by-criterion evidence and exact results.

# Handoff — secure API assertion builder and sample endpoints

Outcome: completed

Implemented the backend-owned API assertion builder persistence and HTTP surface.

## Integration contract

- `GET /checkybot/api-monitors/{monitor_uuid}/assertions` returns the Inertia builder component with shared API identity, versioned endpoint/method/assertions, only fixed header masks, and `sample: null`.
- `POST /checkybot/api-monitors/{monitor_uuid}/sample` validates optimistic version and header mutations, enforces outbound SSRF/time/redirect/body limits, and returns parsed bounded JSON plus deterministic canonical paths or the declared redacted manual-entry failure.
- `PUT /checkybot/api-monitors/{monitor_uuid}/assertions` atomically normalizes and saves ordered assertions and encrypted header mutations, incrementing the version; stale writes return 409 without partial state.
- Current-project authorization runs before configuration or encrypted-header access. Foreign API monitors return the declared 403 and unknown/wrong-type monitors return 404.
- Stored secret values never cross GET/save/sample responses. `preserve` decrypts only at the outbound request boundary.

## Main files

- `database/migrations/2026_08_06_040000_create_api_monitor_builder_tables.php`
- `src/Domain/ApiMonitorBuilder/**`
- `src/Http/Controllers/ApiMonitorBuilderController.php`
- `routes/web-dashboard.php`
- `tests/Unit/ApiMonitorBuilder/**`
- `tests/Feature/WebDashboard/ApiAssertionBuilderTest.php`
- `tests/Feature/WebDashboard/ApiMonitorSampleTest.php`

## Verification

- Milestone target: 18 tests, 143 assertions passed.
- Full backend: 329 tests, 1880 assertions passed.
- PHPStan and scoped Pint passed.

Detailed AC evidence and database-safety notes are in `ledger.md`.

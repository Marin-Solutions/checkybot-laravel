# Canonical status-summary API and consumer contract handoff

Implemented all four acceptance criteria for `backend-status-summary`.

## Delivered

- `GET /api/status-summary` with active bearer-token authentication, `status:read` authorization, exact 401/403 responses, and project isolation.
- Typed status-summary read model/resource with nine integer cells, recovering→warn mapping, newest-state timestamp, and strict `> 900` second staleness.
- Mobile/widget end-to-end Pest seam through real harness HTTP, registered relay, independent real queue worker, receipt HTTP, and authenticated summary HTTP.
- Generated TypeScript status DTO/client consumed by the contract-only Expo fixture.
- Full-runtime Playwright web seam comparing delivered web-receipt summary, authenticated API summary, and all rendered values.
- Run-scoped harness migration/token seed and `/api` proxy support; no production UI was added.
- Corrected the pre-existing subprocess worker test helper so all monitoring-foundation queue tests now execute against their verified workspace SQLite file.

## Verification

- Full Pest: 238 tests / 759 assertions passed.
- PHPStan: no errors.
- Pint and `git diff --check`: passed.
- Generated contract check: passed.
- Full runtime integration: passed, run `68d20325-3183-43e2-aa0d-842106d8a11a`.

Evidence is recorded in the milestone ledger and under `build/harness-runs/68d20325-3183-43e2-aa0d-842106d8a11a/`.

# Backend dashboard read surface — implementation ledger

Milestone: `backend-dashboard-read-surface`  
Task: `04255cfd-7a61-439f-a0e7-cede1638f7c0`

## Delivered

- Registered authenticated, web/session-aware dashboard and monitor-detail routes in `routes/web-dashboard.php`.
- Added one-current-project resolution and authorization without accepting a request project override.
- Added bounded validation and cursor pagination for problem type/state/severity/monitor filters.
- Adapted the inherited status summary, maintenance silencer/redactor, monitor identity, and incident timeline contracts into Inertia-compatible page responses.
- Added a testing/harness-only loopback web session fixture; it is not registered outside `testing|harness`.
- Added feature coverage for dashboard isolation/filtering/pagination, detail authorization/timeline parity, and the real alerting HTTP → database queue worker → foundation relay → queue worker → X-Inertia seam.
- No migration was required.

## Acceptance evidence

| Criterion | Evidence |
|---|---|
| `AC-web-dashboard-api-builder-1` | `DashboardReadSurfaceTest.php`: guest redirect; exactly one authorized current project despite an override query; canonical nine summary cells/freshness; redacted effective global maintenance; default problem states; all filter validations; opaque cursor pagination; foreign-project exclusion. |
| `AC-web-dashboard-api-builder-2` | `DashboardReadSurfaceTest.php`: unknown and wrong type 404, foreign project 403, and exact HTTP timeline parity with `IncidentTimelineReadModel`, including ordered durations, group membership, suppression flags, and null annotation values. |
| `AC-web-dashboard-api-builder-3` | `AlertingTimelineSeamTest.php`: three real `POST /__harness/alerting/results` calls, two real subprocess `queue:work` drains around the registered `checkybot:foundation-relay`, harness session authentication, and final real X-Inertia detail response containing down/group data. The test does not import or invoke the processor, transition action, read model, or web controller. |

## Verification results

| Command | Exit | Result |
|---|---:|---|
| `./vendor/bin/pest tests/Feature/WebDashboard --compact` | 0 | 3 passed, 84 assertions; includes real queue-worker subprocess seam. |
| `./vendor/bin/pest --compact` | 0 | 311 passed, 1737 assertions. |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | 0 | No errors. |
| `./vendor/bin/pint --test src/Http/Controllers/WebDashboard routes/web-dashboard.php tests/Feature/WebDashboard src/CheckybotLaravelServiceProvider.php` | 0 | Passed. |

Logs: `build/backend-dashboard-read-surface-pest.log`, `build/backend-dashboard-read-surface-full-pest.log`, `build/backend-dashboard-read-surface-phpstan.log`, and `build/backend-dashboard-read-surface-pint.log`.

## Iteration notes

- Initial targeted run failed because the web session middleware needed a test application key; the test-local key was added and the rerun passed.
- An attempted standard `auth` middleware stacking check returned 401 before the testing session hydrator due middleware priority. The route uses the package's web-operator middleware, which consumes the existing authenticated request user and only hydrates a synthetic user for the testing/harness-only session seam.
- The first full random-order regression exposed a test-fixture persistence issue for a manually created suppression flag. The fixture now writes immutable transition metadata directly, matching the established alerting timeline test fixture; the final full suite passed.

## Database safety

All database-backed tests create UUID-named SQLite files under workspace-local `build/`, configure that connection before schema setup, and remove the files afterward. No destructive Artisan migration/database command was run. The seam uses the real database queue driver and worker against the same workspace-local SQLite file.

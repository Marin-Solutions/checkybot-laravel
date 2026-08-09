# Review — backend-dashboard-read-surface

Verdict: `review_approved`

Review round: 1 (no prior review artifact was present).

## Scope reviewed

- Spec section: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/slice-spec.json` / `backend-dashboard-read-surface`
- Milestone ledger: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/backend-dashboard-read-surface/ledger.md`
- Implementation files: `routes/web-dashboard.php`, `src/Http/Controllers/WebDashboard/*`, `src/CheckybotLaravelServiceProvider.php`, `tests/Feature/WebDashboard/*`

## Verification rerun

| Command | Result | Evidence |
|---|---:|---|
| `./vendor/bin/pest tests/Feature/WebDashboard --compact` | Pass | 3 passed, 84 assertions (rerun in review container). |
| `./vendor/bin/pest --compact` | Pass | 311 passed, 1737 assertions (rerun in review container). |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | Pass | No errors (rerun in review container). |
| `./vendor/bin/pint --test src/Http/Controllers/WebDashboard routes/web-dashboard.php tests/Feature/WebDashboard src/CheckybotLaravelServiceProvider.php` | Pass | `{"tool":"pint","result":"passed"}` (rerun in review container). |

No destructive Artisan migration/database command was run during review.

## Acceptance criteria

| ID | Result | Evidence |
|---|---|---|
| `AC-web-dashboard-api-builder-1` | Pass | `tests/Feature/WebDashboard/DashboardReadSurfaceTest.php` covers guest redirect to `/login`, ignores `project_uuid` override in favor of the operator's current project, returns the canonical 3x3 counts with freshness and RFC3339 `updated_at`, redacts effective maintenance props, defaults states to `warn/down/recovering`, validates malformed enum/UUID/cursor/per-page/duplicate filters as 422, applies normalized filters, cursor-paginates, and asserts foreign-project monitor IDs are excluded. `OverviewController`, `DashboardFilters`, and `ProblemProjection` implement this through current-project resolution and bounded queries. |
| `AC-web-dashboard-api-builder-2` | Pass | `DashboardReadSurfaceTest.php` covers 404 for unknown monitor, 404 for wrong type, 403 for a foreign project, and an authorized monitor response whose `props.timeline` exactly matches `IncidentTimelineReadModel`, including ordered durations `[40, 30, null]`, incident-group membership, maintenance-suppression flags, and nullable annotation slots. `MonitorDetailController` binds `AuthorizedMonitorIdentity` to the resolved current project before reading the timeline. |
| `AC-web-dashboard-api-builder-3` | Pass | `tests/Feature/WebDashboard/AlertingTimelineSeamTest.php` posts three real HTTP results to `/__harness/alerting/results`, authenticates via `/__harness/web-dashboard/authenticate/{projectUuid}`, executes the registered `checkybot:foundation-relay`, drains jobs via a subprocess that calls Laravel `queue:work --stop-when-empty` against the same workspace-local SQLite database, then reads the real X-Inertia `GET /checkybot/monitors/server/{monitor}` response and observes the down transition plus incident group. The seam test does not import or call the result processor, transition service, timeline read model, or web controller directly. |

## Notes

- Data-integrity lens: no migrations were introduced. Tests configure UUID-named SQLite files under workspace `build/` and remove them after use; the async seam uses the database queue with `after_commit` and a real `queue:work` command in a separate PHP process.
- Harness-only routes are guarded by `testing|harness` environment checks plus loopback middleware and are not registered for production environments.

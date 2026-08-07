# Handoff — authorized dashboard read surface

Outcome: completed

Implemented the backend-owned dashboard overview and monitor timeline HTTP surface.

## Integration contract

- `GET /checkybot` returns `CheckybotDashboard/Overview` with normalized filters, inherited canonical summary/freshness, project-isolated cursor-paginated problems, and effective redacted maintenance props.
- `GET /checkybot/monitors/{type}/{monitor_uuid}` returns `CheckybotDashboard/MonitorDetail` with shared identity plus the inherited timeline transition/group/annotation shapes.
- X-Inertia or JSON requests receive `{component, props, url, version}` with `X-Inertia: true`; plain HTML receives an Inertia-compatible `data-page` root.
- Invalid dashboard filters return a bounded 422 `{message, errors}` response before summary/problem queries.
- The authenticated operator's selected current project is authoritative; request project override fields are ignored.
- The loopback route `GET /__harness/web-dashboard/authenticate/{projectUuid}` is registered only in `testing|harness` and establishes the deterministic web operator session required by runtime journeys.

## Main files

- `routes/web-dashboard.php`
- `src/Http/Controllers/WebDashboard/*`
- `tests/Feature/WebDashboard/DashboardReadSurfaceTest.php`
- `tests/Feature/WebDashboard/AlertingTimelineSeamTest.php`

No schema changes were introduced.

## Verification

- Targeted: 3 tests, 84 assertions passed.
- Full backend: 311 tests, 1737 assertions passed.
- Full PHPStan and scoped Pint passed.

Detailed evidence and database-safety notes are in `ledger.md`.

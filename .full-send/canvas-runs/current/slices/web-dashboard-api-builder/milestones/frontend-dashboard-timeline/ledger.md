# Frontend dashboard and timeline — implementation ledger

Milestone: `frontend-dashboard-timeline`  
Task: `5cee1d3c-1c5a-41a5-8ace-45dc61733805`

## Delivered

- Added typed React Inertia page modules for `CheckybotDashboard/Overview` and `CheckybotDashboard/MonitorDetail`.
- Added reusable shadcn-style React composition primitives and Tailwind-token styling for the canonical 3×3 status grid, problem filters/list, maintenance status banner, lifecycle/severity badges, transitions, incident groups, annotations, and deterministic async/empty/error states.
- Dashboard navigation sends real `X-Inertia` requests, applies normalized server props, updates browser history, restores results on `popstate`, and keeps notification-provided monitor UUID filters in every subsequent query.
- Review-round repair makes filter and `popstate` navigation last-write-wins with a monotonic request sequence. Only the latest mounted navigation may change page/loading/error/history state; prop refreshes and unmounts invalidate in-flight work.
- Timeline rendering performs stable chronological ordering, labels completed versus current/open durations, exposes maintenance suppression, and renders group members and nullable annotation values explicitly.
- No backend, route, migration, Filament, package manifest, or unrelated mobile files were changed.

## Acceptance evidence

| Criterion | Evidence |
|---|---|
| `AC-web-dashboard-api-builder-8` | `Overview.test.tsx` proves exactly nine host-rendered labeled count cells, localized freshness, stale-not-healthy behavior, and explicit all-healthy, problems-present, empty-project, loading, and error states while prior contract-valid results remain visible. |
| `AC-web-dashboard-api-builder-9` | `ProblemNavigation.test.tsx` proves normalized type/state/severity/monitor UUID initialization, exact Inertia query updates, UUID preservation, server-returned-only problem rendering, exact authorized detail links, and UUID retention through browser `popstate` plus remounted reload props. Its deterministically interleaved deferred-response regression starts an older filter request, restores back-navigation first, then resolves the stale request and proves restored filters, problems, UUID query, history, and loading state remain authoritative. A separate deferred test proves unmounted requests cannot commit page/history state. |
| `AC-web-dashboard-api-builder-10` | `MonitorDetail.test.tsx` proves stable chronological transition rendering, completed/current-open duration labels, state/severity/suppression labels, affected group members, explicit empty timeline/groups, and null-versus-present annotation rendering. |
| `AC-web-dashboard-api-builder-11` | `MaintenanceBanner.test.tsx` covers active global and project scopes, localized end time, inactive omission, sticky responsive classes, semantic status role, focus ring, accessible name, and keyboard focusability. |

## Verification results

| Command | Exit | Result |
|---|---:|---|
| `npx tsc -p resources/js/tsconfig.json --noEmit` | 0 | Strict TypeScript check passed with no output. |
| `npx jest --config tests/Component/WebDashboard/jest.config.cjs --runInBand` | 0 | 4 suites, 13 tests, 0 snapshots passed after the navigation-race repair. |

Evidence logs:

- `evidence/typecheck.log`
- `evidence/component-tests.log`

## Scope and safety notes

- The slice declares no owned reference screens, so no visual-diff capture is required; behavior, responsiveness, and accessibility are authoritative.
- No database command was needed or run. No database, Redis, Supervisor, or host-service operation was performed.
- Root `.env.example` was pre-existing and untracked; it was not modified.

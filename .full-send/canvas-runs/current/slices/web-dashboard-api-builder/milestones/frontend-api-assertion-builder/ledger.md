# Ledger — Interactive JSON-path API assertion builder

## Scope

Implemented only `frontend-api-assertion-builder` within the declared `resources/js`, CSS/shared UI, `tests/Component/WebDashboard`, and `tests/Feature/WebDashboard` ownership. No production backend controller, domain, route, migration, config, Filament, or unrelated surface was changed.

## Acceptance evidence

| Criterion | Implementation and proof |
|---|---|
| AC-web-dashboard-api-builder-12 | `ApiAssertionBuilder`, `JsonPathPicker`, and `ApiAssertionBuilderSample.test.tsx` prove bounded JSON/status/latency rendering, roving keyboard tree navigation, Enter selection, new/existing canonical-path insertion, and preservation of unrelated endpoint/assertion drafts. |
| AC-web-dashboard-api-builder-13 | `HeaderEditor` starts stored rows in preserve mode, emits no value for preserve/remove, exposes a password input only after Replace, and rebuilds state only from masked response fields. `ApiAssertionBuilderHeaders.test.tsx` checks DOM/serialized output, request payloads, replace, and removal. |
| AC-web-dashboard-api-builder-14 | Typed sample failure handling renders only bounded declared redacted messages for fetch_timeout, fetch_failed, non_json, upstream_auth, 409, and 422. Table-driven component tests prove endpoint/header/assertion drafts, labeled manual JSON-path input, and save remain usable. |
| AC-web-dashboard-api-builder-15 | `AssertionEditor` supports all three kinds, operator-appropriate operands, stable ordered add/move/edit/remove, and index-specific errors. The editing test proves 422 row mapping, 409 reload prompt without draft replacement, preserve request shape, and replacement only from a successful normalized masked version-incremented response. Existing real Laravel request tests passed 59 assertions for ordered/atomic save semantics. |
| AC-web-dashboard-api-builder-16 | `ApiAssertionBuilderRuntime.spec.ts` launches a dev Metro React surface, run-scoped Laravel server, deterministic upstream, and real database queue worker. It posts three real alerting result HTTP requests, waits for down, executes the registered relay command, observes its worker receipt, authenticates via the harness fixture, follows overview/problem/detail Inertia reads, checks project maintenance, exercises real sample/save routes with a preserved mask and keyboard path pick, and checks real upstream-401 manual fallback. No controller, processor, read model, or component is imported/invoked by the Playwright test. Runtime evidence is in `evidence/runtime-evidence.json`. |

## Verification results

| Command/stage | Exit | Result |
|---|---:|---|
| `npx tsc -p resources/js/tsconfig.json --noEmit` | 0 | Strict production component TypeScript passed. |
| `npx tsc -p tests/Feature/WebDashboard/RuntimeApp/tsconfig.json --noEmit` | 0 | Runtime React entrypoint TypeScript passed. |
| `npx jest --config tests/Component/WebDashboard/jest.config.cjs --runInBand` | 0 | 8 suites, 24 tests passed; 0 snapshots. |
| `./vendor/bin/pest tests/Feature/WebDashboard/ApiAssertionBuilderTest.php --compact` | 0 | 2 request tests, 59 assertions passed. |
| `node tests/Feature/WebDashboard/Runtime/runtime-stage.mjs prepare` | 0 | Created a unique workspace run directory and SQLite path. |
| `node tests/Feature/WebDashboard/Runtime/runtime-stage.mjs start` | 0 | Printed effective config, migrated only verified run-scoped SQLite, seeded fixture, and started Laravel, real queue worker, upstream, and Metro dev server. |
| `./node_modules/.bin/playwright test --config tests/Feature/WebDashboard/playwright.config.ts` | 0 | Canonical full-runtime journey passed (1/1, final run 4.3s). |
| `node tests/Feature/WebDashboard/Runtime/runtime-stage.mjs stop` | 0 | Stopped only PID groups whose environment matched the unique runtime id. |

## Database and lane safety

Before the only migration command, runtime startup printed `database.default = sqlite` and the effective SQLite database under `build/web-dashboard-api-runtime/<uuid>/database.sqlite` inside this workspace. The runtime rejects any database path outside the workspace, creates a fresh UUID run directory, and uses only non-destructive `migrate` against that new file. No destructive migration, database/Redis shutdown, Supervisor operation, critical service control, or host package installation was run.

## Artifacts

- `evidence/component-tests.log`
- `evidence/request-tests.log`
- `evidence/typescript.log`
- `evidence/runtime-prepare.log`
- `evidence/runtime-start.log`
- `evidence/playwright.log`
- `evidence/runtime-stop.log`
- `evidence/runtime-evidence.json`
- `evidence/upstream.ndjson` (records only `[PRESENT]`, never a credential)

# Frontend final states and runtime proof ledger

## Scope

Implemented only `frontend-final-states-runtime-proof` under the declared `tests/Component/ReleaseHardening` and handover-checklist ownership. Production mobile/widget components were imported without modification.

## Acceptance evidence

- **AC-release-hardening-6 — PASS**
  - `StatusSurfaceStates.test.tsx` imports production `StatusScreen` and proves first-load progress, explicit zero-problem healthy copy, exact nonzero problem rows, and persistent cached counts plus last-sync age after refresh failure.
  - Component run: 2 suites, 12 tests, exit 0.
- **AC-release-hardening-7 — PASS**
  - `WidgetFreshnessFailClosed.test.ts` imports production `StatusWidgetTimelineProvider` and `renderStatusWidget` with a controlled clock.
  - Proves age visibility at 0/900/>900 seconds and fail-closed null timestamp, server stale, 401, 403, and transport states. Every stale/error assertion rejects `healthy-green`, healthy phase, and healthy status copy.
- **AC-release-hardening-8 — PASS**
  - Canonical run `ccbf1402-b376-4e03-bbf3-4442f8a5a9dc` exported and statically served the production `StatusScreen` through Expo web.
  - Effective database config was printed before migration and proved SQLite at `build/release-hardening-runtime/ccbf1402-b376-4e03-bbf3-4442f8a5a9dc/database.sqlite`.
  - Real HTTP plus the real database queue worker proved a 20-second blip yielded no intent and two push-receipt 404s; a two-monitor confirmed critical group yielded one Expo acceptance and one accepted legacy webhook after duplicate replays; authenticated status summary exposed `servers.down=2`; an aborted surfaced refresh retained the cached count and visible last-sync age in the offline state.
- **AC-release-hardening-9 — PASS**
  - Completed handover manifest: `.full-send/canvas-runs/current/handover-checklists/evidence/frontend-final-states-runtime-proof/ccbf1402-b376-4e03-bbf3-4442f8a5a9dc/handover-evidence-manifest.json`.
  - Contains run/commit/timestamps, exact staged commands and endpoints, SQLite path, worker start/processed proof, HTTP snapshots, test summaries, screenshots, sanitized trace, logs, watchdog/proving/fleet references, zero-match secret scan, and all recorded PIDs dead after cleanup.

## Verification results

| Command | Exit | Result |
|---|---:|---|
| `node tests/Component/ReleaseHardening/runtime-stage.mjs prepare` | 0 | Run-scoped workspace directory and SQLite path allocated |
| `node tests/Component/ReleaseHardening/runtime-stage.mjs build` | 0 | Expo web export completed |
| `node tests/Component/ReleaseHardening/runtime-stage.mjs component` | 0 | 2 suites / 12 tests passed |
| `node tests/Component/ReleaseHardening/runtime-stage.mjs start` | 0 | Printed effective SQLite config; migrated local run DB; app, worker, and static server ready |
| `node tests/Component/ReleaseHardening/runtime-stage.mjs playwright` | 0 | 1 canonical journey passed in 23.3s |
| `node tests/Component/ReleaseHardening/runtime-stage.mjs stop` | 0 | backend=false, worker=false, frontend=false |
| `node tests/Component/ReleaseHardening/runtime-stage.mjs finalize` | 0 | Handover manifest emitted; secret scan matches=0 |
| `node_modules/.bin/tsc --noEmit -p tests/Component/ReleaseHardening/RuntimeApp/tsconfig.json` | 0 | Runtime app typecheck passed |
| `php -l tests/Component/ReleaseHardening/Runtime/seed.php` | 0 | PHP syntax passed |
| `node --check tests/Component/ReleaseHardening/runtime-stage.mjs` | 0 | Runtime-stage syntax passed |
| handover evidence contract assertions | 0 | Manifest snapshots, delivery counts, secret scan, and cleanup validated |

No destructive database command was run. The only migration targeted the printed workspace-local run-scoped SQLite file.

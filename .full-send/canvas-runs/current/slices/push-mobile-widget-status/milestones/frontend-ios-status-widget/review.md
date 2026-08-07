# Review — iOS WidgetKit nine-number status widget

Verdict: `review_approved`

Review round: 1 (no prior review artifact was present).

## Verification rerun

All manifest commands were rerun from the shared workspace and matched the ledger claims:

1. `npm --prefix mobile run typecheck` — PASS, exit 0.
2. `npm --prefix mobile test -- --watch=false` — PASS, exit 0; 7 suites, 37 tests, 1 snapshot passed.
3. `npm --prefix mobile run widget:config:verify` — PASS, exit 0; isolated Expo iOS prebuild verifier reported one medium iOS extension, idempotent bridge, and no Android widget module.

Additional safe inspection: reviewed the generated-widget config/plugin tests, timeline tests, refresh bridge tests, TypeScript timeline/refresh models, Expo plugin, and Swift WidgetKit sources. No database, migration, queue, service-control, or destructive command was run.

## Acceptance criteria

| ID | Result | Evidence |
| --- | --- | --- |
| AC-push-mobile-widget-status-11 | PASS | `WidgetNativeConfig.test.ts` fixes one configured `./plugins/withStatusWidget`, iOS-only constants, no Android widget path, medium family, row order `Servers`, `Websites`, `APIs`, and column order `healthy`, `warn`, `down`. `npm --prefix mobile run widget:config:verify` passed after two isolated iOS prebuild passes and asserted exactly one app-extension product, widget Info.plist/source layout, idempotent AppDelegate bridge, and no generated Android directory. |
| AC-push-mobile-widget-status-12 | PASS | `WidgetTimeline.test.ts` stubs HTTP and proves consecutive scheduled reloads each call `GET https://status.example.test/api/status-summary` with `Accept: application/json` and bearer auth, map all nine counts plus `updated_at`, return auth entries for 401/403, return offline for transport failure, and preserve API-derived data rather than silent-push lookalike counts. Swift source inspection confirms the WidgetKit provider builds the direct `/api/status-summary` request with the bearer header. |
| AC-push-mobile-widget-status-13 | PASS | Clock-controlled widget tests prove 900 seconds is still fresh, 901 seconds is stale, and `Updated Xm ago` remains present. Null `updated_at` and server `stale=true` render the stale phase with `Stale data`, `warning-dimmed`, and `dimmed=true`; source `deriveFreshness` uses the same `> 900` boundary. |
| AC-push-mobile-widget-status-14 | PASS | Layout/link tests assert the row-major 3x3 grid and exact affected warn/down cells, healthy zero warn/down treatment (`All systems healthy`, `healthy-green`), and non-green loading/auth/offline tones. The widget view model and Swift widget URL use `checkybot://problems?states=warn,down` with optional project UUID. |
| AC-push-mobile-widget-status-15 | PASS | `WidgetRefreshBridge.test.ts` proves a valid `refreshWidget=true` payload records the operation and calls the reloader once, duplicates are ignored, and malformed payloads do not record or reload. Native source inspection confirms shared-container `UserDefaults` hint persistence, UUID dedupe guarded by `NSLock`, bounded operation history, and `WidgetCenter.shared.reloadTimelines(ofKind:)`. Timeline throttling coverage proves the next scheduled reload still fetches `/api/status-summary` directly and preserves visible update age. |

## Notes

- The milestone is a standard milestone review, not slice integration/acceptance, so the lightweight test/prebuild evidence is sufficient under the provided surface rule.
- No acceptance criterion depends on Laravel queue-worker runtime evidence in this milestone.

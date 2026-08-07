# iOS WidgetKit nine-number status widget — implementation ledger

Milestone: `frontend-ios-status-widget`  
Scope: `mobile`, `tests/Component/MobileStatus`, and this milestone's configured artifacts only.

## Delivered

- Added an idempotent Expo config plugin that generates one iOS `com.apple.product-type.app-extension` target, embeds it in the app, installs shared App Group/keychain entitlements, and adds no Android mod or module.
- Added the WidgetKit/SwiftUI medium widget with the fixed Servers, Websites, APIs row order and healthy, warn, down column order.
- Added a native timeline provider whose system reload entry point always makes an authenticated direct `GET /api/status-summary`, strictly requires all nine counts, caches only the last successful API response, and emits deterministic loading/auth/offline entries.
- Added age and freshness rendering with the exact 900-second fresh boundary, explicit stale warning tint/dimming, problem-cell highlighting, all-healthy treatment, and warn/down problems links.
- Added the AppDelegate refresh hook and native shared-container bridge. It validates `refreshWidget=true` plus UUID `operation_id`, records before reloading, bounds dedupe history, and invokes `WidgetCenter.reloadTimelines(ofKind:)` once per operation.
- Added testable TypeScript timeline/view and refresh models plus native-source/config snapshots and an isolated two-pass Expo prebuild verifier.

## Acceptance evidence

### AC-push-mobile-widget-status-11

- `WidgetNativeConfig.test.ts` snapshots the only declared widget plugin, `systemMedium` family, row order, and column order.
- `npm --prefix mobile run widget:config:verify` performs two isolated Expo iOS prebuild passes and asserts exactly one app-extension product, an app-to-extension dependency, WidgetKit Info.plist, medium-only source, idempotent AppDelegate bridge, and no generated Android directory.
- Result: PASS, exit 0. Log: `build/ios-status-widget-config.log`.

### AC-push-mobile-widget-status-12

- `WidgetTimeline.test.ts` invokes consecutive scheduled reloads against an HTTP stub and proves each call is `GET https://status.example.test/api/status-summary` with `Accept: application/json` and `Authorization: Bearer ...`.
- The test verifies all nine mapped integers and `updated_at`, auth entries for 401/403, offline entry for transport failure, and rejection of silent-push lookalike counts as a timeline source.
- Native snapshot checks retain the direct endpoint and bearer-header implementation.
- Result: PASS.

### AC-push-mobile-widget-status-13

- Clock-controlled cases prove exactly 900 seconds is fresh, 901 seconds is stale, and the age remains visible as `Updated Xm ago`.
- `updated_at=null` and server `stale=true` both produce `Stale data`, `warning-dimmed`, and `dimmed=true`; local age over 900 seconds follows the same path.
- Result: PASS.

### AC-push-mobile-widget-status-14

- Layout tests assert the complete row-major nine-cell snapshot and the exact six affected warn/down cells in the problem fixture.
- Zero warn/down counts render `All systems healthy` with `healthy-green`; loading, auth, and offline use neutral, never green, treatment.
- Every view model produces `checkybot://problems?states=warn,down` and includes the active project UUID when available.
- Result: PASS.

### AC-push-mobile-widget-status-15

- `WidgetRefreshBridge.test.ts` proves a valid delivered payload records the operation timestamp and reloads once, duplicate delivery does neither again, and malformed flag/UUID/project payloads are ignored.
- The throttling simulation omits the best-effort push callback, advances the clock, then proves the next scheduled timeline reload still performs direct HTTP and an offline response retains the API-derived counts and visible `Updated 15m ago` age.
- Native source uses an `NSLock`, persists the hint and bounded operation IDs before calling `WidgetCenter`, and never overwrites cached status/age with push data.
- Result: PASS.

## Verification commands and real results

1. `npm --prefix mobile run typecheck` — exit 0; strict TypeScript passed. Log: `build/ios-status-widget-typecheck.log`.
2. `npm --prefix mobile test -- --watch=false` — exit 0; 7 suites, 37 tests, 1 snapshot passed. Log: `build/ios-status-widget-tests.log`.
3. `npm --prefix mobile run widget:config:verify` — exit 0; isolated two-pass native generation checks passed. Log: `build/ios-status-widget-config.log`.

## Database safety

No database or service command was needed or run. No migration command was run.

## Notes

- Existing authenticated app provisioning of the project `status:read` token remains the external prerequisite declared by the slice contract.
- An unrelated root `.env.example` was already untracked and was not modified by this milestone.

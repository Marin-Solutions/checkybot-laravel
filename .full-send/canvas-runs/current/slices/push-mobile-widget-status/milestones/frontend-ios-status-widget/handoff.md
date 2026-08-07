# Handoff — iOS WidgetKit nine-number status widget

Outcome: completed

Implemented AC 11–15 within mobile ownership: one idempotently generated iOS-only medium WidgetKit extension, direct authenticated status-summary timelines, deterministic nine-cell/freshness/offline rendering, warn/down problems links, and a deduplicated shared-container refresh bridge.

## Verification summary

- Strict TypeScript: passed.
- React Native/widget contract tests: 7 suites, 37 tests, and 1 snapshot passed.
- Isolated Expo native generation: two successive iOS prebuild passes produced exactly one embedded WidgetKit extension and one AppDelegate hook, with no Android widget module.
- Direct-fetch, all-nine-count mapping, 401/403/transport states, 900/901-second freshness boundary, exact affected cells, non-green loading/offline, deduplicated refresh operations, and throttled-background fallback are covered.

Detailed criterion evidence and command logs are recorded in `ledger.md` and `build/ios-status-widget-*.log`. Existing app provisioning of the project `status:read` token remains the declared external prerequisite.

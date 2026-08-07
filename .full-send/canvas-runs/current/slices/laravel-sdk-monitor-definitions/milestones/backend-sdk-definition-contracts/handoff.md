# V1 fluent and config monitor definition contracts — handoff

Outcome: completed

## Delivered

- Added fluent domain-expiry and response-time-budget definitions with v1 defaults and explicit-threshold support.
- Added complete registry/facade/getter/count/flush/config/stub support for all seven monitor types.
- Added a shared `CheckSyncPayloadSerializer` producing the canonical `check-sync.v1` envelope from both fluent and config declarations, including legacy config aliases.
- Added deterministic typed API status, maximum-latency, and chained JSON response assertion metadata while retaining existing fluent aliases.
- Expanded local validation for names, HTTP(S) URLs, intervals, bounded thresholds/options, and assertion combinations.
- Added masked object/debug/JSON/native serialization plus sync exception/log redaction; the fixed secret corpus appears only in the captured outbound HTTPS request body.
- Updated command payload consumption to the canonical array names required by this definition milestone.

## Verification

- Focused suite: 194 tests passed, 468 assertions.
- Full suite: 311 tests passed, 1867 assertions.
- PHPStan: no errors.
- Pint: passed.

## Follow-up seam note

The foundation-owned checked-in schema still exposes the older five-array `checkSync` shape. It was intentionally not modified under this milestone's ownership and was reported in Canvas message `35d011aa-7893-480d-a71f-19a4c4a5bac0` for the later contract-integration milestone.

## Key artifacts

- Ledger: `.full-send/canvas-runs/current/slices/laravel-sdk-monitor-definitions/milestones/backend-sdk-definition-contracts/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/laravel-sdk-monitor-definitions/milestones/backend-sdk-definition-contracts/manifest.json`

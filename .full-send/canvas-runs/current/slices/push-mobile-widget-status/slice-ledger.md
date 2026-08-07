# Slice ledger — push-mobile-widget-status

Review task: `b1ba2008-5eb4-4765-b4d9-c6fc28ad6f46`  
Spec: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/slice-spec.json`

## Scope reviewed

- Backend push-device registration, NotificationIntentCreated consumption, Expo push channel, legacy-webhook proving ledger, reliability read model, and harness receipt seam.
- Expo mobile status shell, secure device-token lifecycle, notification/widget deep links, cached offline behavior, and production-shaped Expo exports.
- iOS-only WidgetKit 3x3 status widget, direct status-summary timeline fetch, stale rendering, link routing, and refresh bridge.

Implementation uses this package repository's `src/Domain/Push`, `src/Http/Controllers/PushDeviceController.php`, `src/Notifications/Channels/ExpoPushChannel.php`, `routes/push.php`, `database/migrations/2026_08_06_020000_create_push_delivery_tables.php`, `mobile/`, and owned tests as the package-path equivalents of the slice ownership declaration.

## Rework budget

- Backend milestone review is round 2 and records one prior rework for AC-push-mobile-widget-status-2 and AC-push-mobile-widget-status-4.
- Frontend Expo app milestone review is round 1.
- iOS widget milestone review is round 1.
- No prior slice integration-review artifact exists.

Combined rework rounds observed: **1**, below the third-round exhaustion threshold.

## Device evidence

No `device/device-review.md` or `device/build-failure.md` exists under this slice directory. Per instructions this is recorded as **no device evidence** and the integration review is based on remaining milestone/runtime evidence.

## Acceptance ledger

| ID | Result | Evidence |
| --- | --- | --- |
| AC-push-mobile-widget-status-1 | PASS | Backend review and rerun `./vendor/bin/pest tests/Feature/PushNotifications --compact` passed 8 tests / 146 assertions. `PushDeviceApiTest.php` covers 201 create, 200 token rotation, 204 own delete, 401/403/404/422 cases, and active/superseded token delivery exclusion. |
| AC-push-mobile-widget-status-2 | PASS | Backend push runtime suite passed. Evidence includes real `queue:work` helpers and duplicate/concurrent processing tests proving operation claim once, one attempt per active device, bounded retry, and exact DeviceNotRegistered deactivation. |
| AC-push-mobile-widget-status-3 | PASS | Backend tests verify critical high/default/time-sensitive payloads, warn default/no-sound/passive payloads, badge, shared filter data, `refreshWidget=true`, and no token/credential/webhook/secret metadata in snapshots. Runtime receipt also showed high/default/time-sensitive/refresh widget for critical delivery. |
| AC-push-mobile-widget-status-4 | PASS | Clock-controlled reliability tests passed, proving critical dual-send, warn push-only, duplicate receipt de-dupe, failed/missing pair blocks readiness, and retirement only after 28 complete UTC days. |
| AC-push-mobile-widget-status-5 | PASS | `AlertingToPushEndToEndTest.php` rerun passed. It posts real `/__harness/alerting/results`, runs real queue workers plus registered relay, and observes real `/__harness/push/receipts/{operation_id}` with accepted Expo, accepted legacy webhook, reliability recorded, and refresh widget data. |
| AC-push-mobile-widget-status-6 | PASS | `npm --prefix mobile run typecheck`, `npm --prefix mobile test -- --watch=false`, `npm --prefix mobile run build:ios`, and `npm --prefix mobile run build:android` passed. Contract parsing tests reject unknown states and malformed nine-count responses using generated shared contracts. |
| AC-push-mobile-widget-status-7 | PASS | Mobile Jest suite passed 7 suites / 37 tests. `StatusScreen.test.tsx` covers loading, all-healthy, non-healthy-only problem rows, and failed refresh with cached summary plus `Last synced` offline banner. |
| AC-push-mobile-widget-status-8 | PASS | `DeviceLifecycle.test.ts` covers granted/provisional registration, rotation on one installation, denied permission no-op, sign-out DELETE, secure-storage/native API credential handling, and no rendered/logged tokens. |
| AC-push-mobile-widget-status-9 | PASS | `DeepLinks.test.ts` covers incident/recovery notification filters, widget warn/down route, malformed/foreign-project rejection, and Android/web avoidance of iOS widget bridge loading. |
| AC-push-mobile-widget-status-10 | PASS | Production-shaped web export `npm --prefix mobile run build:web:harness` passed. Full runtime Playwright run `91516bb0-efee-4f7f-9292-09ff20b5ae92` used run-scoped SQLite, real backend HTTP, real database queue worker, built Expo web fixture, authenticated status summary, push receipt, deep-link filter, and offline cache evidence. |
| AC-push-mobile-widget-status-11 | PASS | `npm --prefix mobile run widget:config:verify` passed. Widget config tests/prebuild verify exactly one medium iOS extension, no Android widget module, and 3x3 Servers/Websites/APIs by healthy/warn/down layout order. |
| AC-push-mobile-widget-status-12 | PASS | Widget timeline tests passed, proving each scheduled reload performs authenticated direct `GET /api/status-summary`, maps all nine counts and `updated_at`, handles auth/offline entries, and does not substitute silent-push data. |
| AC-push-mobile-widget-status-13 | PASS | Clock-controlled widget tests prove age always visible, 900 seconds fresh, 901 seconds/null/server-stale render dimmed warning stale treatment instead of healthy. |
| AC-push-mobile-widget-status-14 | PASS | Widget layout/link tests prove healthy zero warn/down state, exact affected problem cells, loading/auth/offline not green, and warn/down problems deep links for every widget state. |
| AC-push-mobile-widget-status-15 | PASS | Refresh bridge tests prove `refreshWidget=true` records and reloads once per operation, duplicates/malformed payloads ignored, and throttling leaves next scheduled direct API reload authoritative while preserving visible age. |

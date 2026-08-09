# Expo push, iOS widget, and mobile status loop — slice specification

## Purpose

Deliver the native glanceability loop after alerting has produced a trusted grouped notification intent. This slice owns authenticated Expo-device registration, the queued `NotificationIntentCreated` consumer, Expo and proving-period fallback delivery records, the thin Expo status app, exactly one iOS WidgetKit status widget, native widget-refresh acceleration, and problem-list deep links.

The foundation remains authoritative for monitor vocabulary and `GET /api/status-summary`; alerting remains authoritative for incident grouping and notification-intent creation. This slice consumes those contracts and does not duplicate monitor state, status aggregation, or incident grouping.

## Base requirements

- PRD §5.3: grouped incident and recovery pushes use the intent's stable thread and affected-monitor filter.
- PRD §5.5: critical pushes are Time Sensitive and audible; warn pushes are silent but badge-visible. Critical traffic remains dual-sent through the existing generic webhook while push proves reliable for four weeks.
- PRD §6.1: ship a thin Expo React Native app. The app remains Android-buildable, while native widget code is iOS-only.
- PRD §6.2: ship exactly one iOS medium 3×3 widget showing Servers, Websites, and APIs crossed with healthy, warn, and down. It fetches the status summary directly, always displays update age, is visibly stale after 15 minutes, and opens filtered problems.
- Inherit foundation monitor types, lifecycle/severity values, freshness rules, monitor filters, redaction, status-summary DTOs, project tokens, and outbox conventions.
- Consume alerting's versioned `NotificationIntentCreated` event without changing its grouping, phase, severity, or dedupe semantics.
- Verification crosses real HTTP, registered relay/listener/jobs, and the real queue worker in the canonical harness.

## Inherited ownership

Implementation is limited to this exact ownership set:

- `app/Domain/Push`
- `app/Http/Controllers/PushDeviceController.php`
- `app/Jobs/Push`
- `app/Notifications/Channels/ExpoPushChannel.php`
- `database/migrations/2026_08_06_020000-2026_08_06_029999`
- `mobile`
- `routes/push.php`
- `tests/Component/MobileStatus`
- `tests/Feature/PushNotifications`
- `tests/Unit/PushNotifications`
- Migration timestamp range: `2026_08_06_020000-2026_08_06_029999`

Milestone ledger, manifest, review, and handoff artifacts are additionally written under this slice's own `milestones/` directory.

## Scope boundaries and invariants

- Foundation `MonitorDomainContracts` are the only source for monitor type, state, severity, freshness, and filter types. No mobile-local enum may diverge.
- Foundation `GET /api/status-summary` is the only nine-count source for the app and widget. This slice must not persist a competing server summary.
- Alerting owns intent creation and grouping. This slice starts only at a valid `notification-intent.v1` outbox event.
- One accepted intent operation is immutable. Listener dedupe is by `operation_id`; provider attempt dedupe is by `operation_id + device_id + channel`.
- Device rows are user- and project-scoped. Expo tokens and provider credentials are secrets and must be encrypted/masked; no API or log returns a token.
- A failing or unregistered device cannot prevent delivery to other active devices.
- The existing generic `WEBHOOK` adapter remains the Telegram fallback. This slice must not introduce a Telegram-specific channel enum.
- The proving ledger records critical dual-send outcomes. It may report retirement readiness only after 28 consecutive complete UTC days with no missing or failed Expo/webhook pair. Actual retirement remains a release gate outside this slice.
- Silent push is only a refresh accelerator. Widget timeline fetches remain authoritative because iOS can throttle silent pushes and widget reloads.
- Stale means `updated_at` is null, the server says stale, or local age is greater than 900 seconds. Exactly 900 seconds is not stale. Stale/offline/loading presentation must never look healthy green.
- Existing mobile-user authentication and provisioning of a `status:read` project token are prerequisites, not new auth flows. Mobile stores credentials in platform secure storage; the iOS extension receives only the read token through its secure App Group container.
- Harness routes are loopback-bound, testing-only, and absent in production.

## Backend-to-frontend API contract

### Device registration

`POST /api/v1/push-devices` requires the existing authenticated mobile-user bearer credential. It accepts a stable installation UUID, valid Expo token, `ios|android`, an authorized project UUID, `granted|provisional` permission, and a bounded app version. A new installation returns `201`; replay or token rotation returns `200`. The unique identity is authenticated user plus installation UUID. Invalid input returns `422`, missing auth `401`, and unauthorized project access `403`.

`DELETE /api/v1/push-devices/{push_device}` deactivates only the authenticated user's device and returns `204`. A foreign or unknown device is not disclosed and returns `404`; replay remains safe.

### Status summary consumption

Both app and WidgetKit timeline provider call foundation-owned `GET /api/status-summary` with an active `ProjectApiToken` possessing `status:read`. The token determines the project. A successful response contains exactly nine non-negative counts under Servers, Websites, and APIs by healthy, warn, and down, plus `updated_at` and `stale`. Missing/invalid auth returns `401`; missing ability returns `403`.

The app uses the generated typed client. WidgetKit calls this endpoint directly on each system timeline reload using the secure shared read token; it must not wait for an app launch or silent push.

### Push-device response safety

Registration responses expose only the public device UUID, installation UUID, platform, project UUID, active state, and registration time. They never return the Expo token or a status-summary token. Authentication/bootstrap outside this slice provisions the latter.

## Event and delivery contract

### `NotificationIntentCreated`

The listener accepts `notification-intent.v1` with operation and intent UUIDs, `incident|recovery`, project/group UUIDs, `warn|critical`, affected shared monitor identities, stable thread key, problem filter, timestamps, and optional recovery downtime. At-least-once delivery is deduplicated before device fan-out.

The push module provider must register the outbox event listener, queued jobs, Expo channel, retry policy, reliability recorder, and harness-only receipt route. Direct service invocation is not valid integration proof.

### Expo payload mapping

Every Expo payload contains a bounded grouped title/body and typed data: problems route, project/group UUIDs, unique monitor UUIDs, phase, severity, stable thread key, and `refreshWidget: true`.

- Critical: `priority=high`, `sound=default`, `interruptionLevel=time-sensitive`, badge, and content-available refresh hint.
- Warn: default priority, no sound, `interruptionLevel=passive`, badge, and content-available refresh hint.

Provider calls are chunked to Expo limits. Retryable transport, timeout, 429, and 5xx failures use bounded queued backoff. `DeviceNotRegistered` deactivates only that token. Accepted ticket IDs and redacted errors are retained as receipts; provider credentials and raw bodies are not.

### Critical dual-send proving

While proving mode is active, each critical intent goes to Expo and the existing generic webhook adapter under the same operation/thread identity. Warn goes only to push. Channel outcomes are independently recorded, so either side can fail without blocking the other.

The internal `PushReliabilityReadModel` reports proving start/end, critical count, accepted counts by channel, missing/failed pairs, consecutive complete days, last failure, and `retirement_ready`. Readiness requires 28 consecutive UTC days in which every critical intent has accepted receipts from both channels. Duplicate receipts do not inflate counts; a missing/failed pair keeps readiness false.

## Owned integration seam

This slice owns `alerting-to-push-delivery`: alerting produces `NotificationIntentCreated`; this slice supplies the registered queued listener, Expo adapter, retry/failure recording, critical fallback invocation, and widget-refresh payload wiring.

The testing-only receipt endpoint `GET /__harness/push/receipts/{operation_id}` reports listener state, redacted per-device Expo outcomes and mapped priority/sound/interruption/refresh fields, legacy-webhook outcome, reliability recording, and processing time.

End-to-end proof must produce confirmed monitor failures via real `POST /__harness/alerting/results` requests, execute the registered alerting grouping dispatcher and foundation outbox relay, run the real queue worker, and observe the push consumer through the real receipt API. Calling an alerting processor, push listener, delivery job, or channel directly does not satisfy the seam.

## Mobile behavior

The Expo Router shell is intentionally thin:

- Loading: visible progress before the first successful summary.
- Healthy: explicit all-healthy copy when all warn/down cells are zero.
- Problems: only non-healthy rows/cells are emphasized, using shared state/severity values.
- Offline/error: persistent error indicator plus the last valid summary and `last synced Xm ago`; with no prior data, an explicit no-data offline state.

The app registers a device only after notification permission is granted or provisional, updates the same installation when Expo rotates the token, and deactivates it on sign-out. Denied permission does not call registration.

Notification taps open the problems route with the exact project, group, and monitor UUID filter carried by the intent. Widget taps open the same route filtered to warn/down. Invalid and foreign-project links are rejected. Android never imports the iOS widget bridge.

## Widget behavior

Ship exactly one iOS-only medium WidgetKit target through an Expo config plugin. The grid order is rows Servers/Websites/APIs and columns healthy/warn/down. Every timeline entry shows update age.

The timeline provider calls `GET /api/status-summary` directly at each scheduled reload. It provides deterministic loading, healthy, problem, stale, and auth/offline entries. Healthy requires zero warn/down cells and fresh data. Problem highlights the exact non-zero warn/down cells. Stale adds dimming, warning tint, and a stale label; auth/offline/loading cannot use the healthy treatment.

When native notification handling receives a valid unique payload with `refreshWidget=true`, it records a shared-container refresh hint and calls `WidgetCenter.reloadTimelines`. Duplicate/malformed payloads do not call it. If iOS throttles background delivery, normal timeline scheduling still performs the direct API fetch and preserves visible age.

## Backend milestone: Registered-device push delivery and proving ledger

<a id="backend-push-delivery-runtime"></a>

### Scope

Implement device persistence/API actions, the queued intent listener, Expo jobs/channel, retry/deactivation behavior, critical generic-webhook proving fan-out, reliability read model, provider fakes, runtime registration, and the harness-only receipt API.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/backend-push-delivery-runtime/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/backend-push-delivery-runtime/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/backend-push-delivery-runtime/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/backend-push-delivery-runtime/handoff.md`

### Acceptance criteria

- **AC-push-mobile-widget-status-1:** Device API feature tests prove creation, token rotation, owned deactivation, all declared auth/validation failures, and exclusion of inactive/superseded tokens.
- **AC-push-mobile-widget-status-2:** Queue/concurrency tests prove listener and per-device/channel idempotency, bounded retry, and isolated `DeviceNotRegistered` deactivation.
- **AC-push-mobile-widget-status-3:** Expo channel tests prove exact critical/warn priority, sound, interruption level, badge, filter, and refresh mapping with no secret leakage.
- **AC-push-mobile-widget-status-4:** Clock-controlled tests prove critical dual-send, warn push-only behavior, receipt dedupe, failure gating, and the strict 28-consecutive-day retirement readiness rule.
- **AC-push-mobile-widget-status-5:** Pest end-to-end proof crosses real alerting POST requests, grouping, relay, real queue worker, and real push receipt GET to observe Expo, fallback, reliability, and widget-refresh effects without direct processor/listener/channel invocation.

## Frontend milestone: Expo mobile status and notification loop

<a id="frontend-expo-status-app"></a>

### Scope

Build the typed Expo Router app shell, status client/states, secure credential adapter, device lifecycle, notification/widget deep-link handling, component suite, and full-runtime Playwright journey.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/frontend-expo-status-app/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/frontend-expo-status-app/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/frontend-expo-status-app/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/frontend-expo-status-app/handoff.md`

### Acceptance criteria

- **AC-push-mobile-widget-status-6:** Locked builds and contract tests prove iOS/Android builds and exclusive use of generated shared status/filter contracts.
- **AC-push-mobile-widget-status-7:** Component tests prove loading, explicit healthy, problem-only, and cached offline/last-synced states.
- **AC-push-mobile-widget-status-8:** Device lifecycle tests prove permission-aware register/rotate/delete behavior and secure-only credential/token handling.
- **AC-push-mobile-widget-status-9:** Router tests prove exact notification and widget filters, rejection of malformed/foreign links, and Android widget isolation.
- **AC-push-mobile-widget-status-10:** Canonical Playwright proof runs the real queue worker and real alerting/push/status APIs, then verifies problem/deep-link/offline app behavior and emits integration evidence.

## Frontend milestone: iOS WidgetKit nine-number status widget

<a id="frontend-ios-status-widget"></a>

### Scope

Add exactly one iOS medium widget target, direct authenticated timeline fetching, healthy/problem/stale/loading/offline entries, filtered app links, secure App Group configuration, and native silent-refresh bridging.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/frontend-ios-status-widget/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/frontend-ios-status-widget/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/frontend-ios-status-widget/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/frontend-ios-status-widget/handoff.md`

### Acceptance criteria

- **AC-push-mobile-widget-status-11:** Configuration/snapshot tests prove exactly one iOS-only medium extension and the declared 3×3 row/column order.
- **AC-push-mobile-widget-status-12:** Timeline tests prove direct authenticated summary fetches on every reload, exact nine-count mapping, auth/offline behavior, and no dependence on app launch or push data.
- **AC-push-mobile-widget-status-13:** Clock tests prove update age is always visible and all null/server/local staleness rules, including the exact 900-second boundary, receive visibly distinct stale treatment.
- **AC-push-mobile-widget-status-14:** Widget tests prove healthy/problem/loading/offline treatments, exact problem cells, no stale green, and warn/down deep links.
- **AC-push-mobile-widget-status-15:** Native bridge tests prove unique valid refresh payloads call WidgetCenter once, malformed/duplicate payloads do not, and throttling leaves direct scheduled fetching authoritative.

## Review expectations

Review must confirm human and machine contracts agree; every implementation and milestone artifact stays within inherited ownership; device and project isolation holds; secrets never enter receipts/logs; listener/provider retries are idempotent; critical and warn mappings match the PRD; the proving ledger cannot shorten or fabricate the 28-day period; the app handles all required states; the widget is exactly one iOS-only 3×3 extension that never displays stale green; silent push remains only an accelerator; deep links use shared filters; and the owned seam and Playwright evidence cross real HTTP, relay/listener/jobs, and a real run-scoped queue worker without direct processor calls.

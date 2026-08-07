# Review — frontend-expo-status-app

Verdict: **review_approved**

Review round: prior `review.md` was absent; canvas loopback count is 1, so this is not the third review round and the rework budget is not exhausted.

## Independent verification

Re-ran the parsed manifest commands in the shared workspace:

- `npm ci --prefix mobile --ignore-scripts` — exit 0, lockfile install completed.
- `npm --prefix mobile run contracts:check` — exit 0, generated contracts current.
- `npm --prefix mobile run typecheck` — exit 0.
- `npm --prefix mobile test -- --watch=false` — exit 0, 4 suites / 16 tests passed.
- `npm --prefix mobile run build:ios` — exit 0, Expo iOS export produced `mobile/build/ios`.
- `npm --prefix mobile run build:android` — exit 0, Expo Android export produced `mobile/build/android`.
- `npm --prefix mobile run build:web:harness` — exit 0, Expo web harness export produced `build/harness-fixture`.
- Runtime journey commands — exit 0: `backend-start`, `seed-device`, `frontend-start`, Playwright, `evidence`, and `stop`.

Runtime run `28dfa8ea-54ab-4db2-9691-06ff9de20885` used a run-scoped SQLite database at `build/harness-runs/28dfa8ea-54ab-4db2-9691-06ff9de20885/database.sqlite`; no destructive database command was run. `worker.log` shows the real database queue worker started and processed `ProcessMonitorResult`, `ProcessIncidentTransition`, `EmitIncidentIntent`, `DeliverFoundationEvent`, `ProcessNotificationIntent`, `DeliverExpoPush`, and `DeliverLegacyWebhook`. Cleanup evidence reports fixture, worker, and app stopped with 0 owned children alive.

## Acceptance criteria

| ID | Result | Evidence |
| --- | --- | --- |
| AC-push-mobile-widget-status-6 | Pass | Locked install, contract check, typecheck, iOS export, and Android export all reproduced with exit 0. `tests/Component/MobileStatus/ContractParsing.test.ts` verifies generated shared contract parsing for status summaries, lifecycle states, severities, freshness, and monitor filters, and rejects unknown state plus missing/extra/negative nine-count responses. |
| AC-push-mobile-widget-status-7 | Pass | Jest reproduced 4/4 suites passing. `StatusScreen.test.tsx` covers initial progress/loading, explicit all-healthy copy, problem state showing only warn/down rows and counts, and failed refresh retaining cached summary with `Last synced 5m ago` plus offline banner. |
| AC-push-mobile-widget-status-8 | Pass | `DeviceLifecycle.test.ts` covers granted/provisional POST registration, token rotation on one stable installation, denied permission with no request, sign-out DELETE for the stored owned device, secure-vault credential clearing, and no credential/token console logging. Grep of `mobile/src`/`mobile/app` found no `AsyncStorage` or product `console.*` calls. |
| AC-push-mobile-widget-status-9 | Pass | `DeepLinks.test.ts` covers incident and recovery notification route params with exact project/group/unique monitor UUID filters, widget warn/down routing, malformed/duplicate/foreign-project rejection, and Android/web not invoking the iOS widget bridge loader. |
| AC-push-mobile-widget-status-10 | Pass | Full runtime Playwright reproduced with a real running queue worker. The journey posted three real `POST /__harness/alerting/results` requests (202), observed a grouped intent, ran the registered relay, observed real `GET /__harness/push/receipts/{operation_id}` with accepted Expo delivery, accepted legacy webhook, reliability recorded, and `refresh_widget=true`, served the Expo web build, made authenticated real `GET /api/status-summary` (200), observed loading-to-problem, validated the notification deep-link filter, forced a 503, and retained cached `servers.down=1` with last-synced state. Evidence files were regenerated under this milestone's `evidence/` directory. |

## Notes

- The implementation is within the milestone's mobile/test ownership. No design-track visual parity criterion applies to AC 6–10.
- Non-blocking maintainability note: `StatusScreen` already guards stale responses with a request sequence; adding an unmount cancellation guard would further harden the screen against navigation-away fetch completion races.

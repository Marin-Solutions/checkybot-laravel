# Backend push delivery runtime — verification ledger

## Scope
Implemented only `backend-push-delivery-runtime`: registered mobile devices, outbox intent consumption, Expo and proving-webhook delivery, idempotent attempts, reliability read model, and testing receipt seam.

## Review round 1 corrections
- Replaced direct push job/process calls with `tests/Feature/PushNotifications/Support/push-queue-worker.php`, which boots the application and executes the real `queue:work` command against a validated workspace-local SQLite file.
- Duplicate intent evidence now starts two barrier-synchronized independent queue worker processes, then drains delivery jobs through a real worker.
- Retry, accepted-ticket replay, and exact `DeviceNotRegistered` deactivation are all exercised by queued jobs through real workers.
- Critical dual-send, warn push-only, duplicate operation delivery, missing-pair failure, and all 28 proving days are produced through real workers.
- `PushReliabilityReadModel` now ends its trailing window on the previous UTC date, excluding the current partial UTC day.

## Acceptance evidence

| Acceptance criterion | Implementation / proof | Result |
|---|---|---|
| AC-push-mobile-widget-status-1 | `PushDeviceApiTest.php` covers 201 registration, 200 token rotation, owner 204 including replay, unauthenticated 401, cross-project 403, private cross-user/unknown 404, malformed UUID/platform/permission/token/version 422, and active/superseded selection. Token values use an encrypted cast and hash-only lookup. | PASS in targeted push suite |
| AC-push-mobile-widget-status-2 | `PushDeliveryRuntimeTest.php` enqueues duplicate `ProcessNotificationIntent` jobs and releases two barrier-synchronized independent `queue:work --once` processes against workspace SQLite. Database uniqueness and the operation claim produce exactly one Expo attempt per active project device and one required webhook attempt. Real workers prove accepted-ticket replay is a no-op, a retryable response schedules bounded queue/job backoff then succeeds on worker retry, and `DeviceNotRegistered` deactivates only the named device. | PASS |
| AC-push-mobile-widget-status-3 | `PushPayloadFactory` assertions prove critical high/default/time-sensitive and warn default/null/passive payloads, integer badge, unique shared filter UUIDs, `refreshWidget`, and absence of token/URL/secret metadata. The same payloads are persisted by real worker deliveries. | PASS |
| AC-push-mobile-widget-status-4 | A clock-controlled test creates 28 daily critical operations, duplicates every process job, and drains all processing plus Expo/webhook jobs through real `queue:work`. It proves 56 unique receipts, critical dual-send, warn push-only, missing legacy failure, 27 complete days at noon on day 28, and readiness only at the next UTC midnight when all 28 days have elapsed. | PASS |
| AC-push-mobile-widget-status-5 | `AlertingToPushEndToEndTest.php` sends real framework HTTP requests to `/__harness/alerting/results`, runs independent database queue workers, invokes the registered foundation relay/grouping flow, then reads `/__harness/push/receipts/{operation_id}` and sees fake Expo acceptance, accepted legacy outcome, reliability evidence, and widget refresh payload. | PASS |

## Verification commands and real results

1. `./vendor/bin/pest tests/Feature/PushNotifications --compact` — exit 0; 8 tests, 146 assertions. Log: `build/push-rework-targeted.log`.
2. `./vendor/bin/phpstan analyse --no-progress` — exit 0; no errors. Log: `build/push-rework-phpstan.log`.
3. `./vendor/bin/pint --test` — exit 0. Log: `build/push-rework-pint.log`.
4. `./vendor/bin/pest --compact` — exit 0; 270 tests, 1226 assertions. Log: `build/push-rework-full-pest.log`.

## Database safety
All database-backed tests create UUID-named SQLite files below workspace `build/` and directly apply only their required migrations to those disposable files. Worker helpers reject database files outside the workspace. No destructive Artisan migration or database command was run.

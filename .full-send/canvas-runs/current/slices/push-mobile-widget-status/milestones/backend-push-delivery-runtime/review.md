# Review — backend-push-delivery-runtime

Verdict: **review_approved**

Review round: 2. Prior round requested changes for AC-push-mobile-widget-status-2 and AC-push-mobile-widget-status-4; this is below the third-round rework budget threshold.

## Verification rerun

I independently reran the manifest and ledger commands:

| Command | Result |
|---|---|
| `./vendor/bin/pest tests/Feature/PushNotifications --compact` | PASS — 8 tests, 146 assertions |
| `./vendor/bin/phpstan analyse --no-progress` | PASS — no errors |
| `./vendor/bin/pint --test` | PASS |
| `./vendor/bin/pest --compact` | PASS — 270 tests, 1226 assertions |
| `./vendor/bin/pest tests/Feature/PushNotifications/AlertingToPushEndToEndTest.php --compact` | PASS — 1 test, 19 assertions |

No destructive database commands were run. The push verification tests create UUID-named SQLite files under workspace `build/` and worker helpers refuse databases outside the workspace.

## Acceptance criteria

| ID | Status | Evidence |
|---|---|---|
| AC-push-mobile-widget-status-1 | PASS | `tests/Feature/PushNotifications/PushDeviceApiTest.php` covers valid authorized 201 registration, 200 same user+installation token rotation, idempotent owner 204 delete, missing auth 401, cross-project 403, cross-user/unknown private 404, malformed UUID/platform/permission/token/version 422, and active/superseded token selection. Targeted push suite passed. |
| AC-push-mobile-widget-status-2 | PASS | `tests/Feature/PushNotifications/PushDeliveryRuntimeTest.php` now dispatches duplicate `ProcessNotificationIntent` jobs and starts two independent barrier-synchronized `queue:work --once` processes via `Support/push-queue-worker.php`, then drains delivery jobs through real `queue:work`. The passed test evidence shows one claim, one Expo attempt per active in-project device, one critical webhook attempt, no inactive/foreign fan-out, accepted-ticket replay no-op, bounded retry/backoff, and `DeviceNotRegistered` deactivation only for the provider-named device. |
| AC-push-mobile-widget-status-3 | PASS | `PushDeliveryRuntimeTest.php` and `PushPayloadFactory` assertions prove critical payloads have `priority=high`, `sound=default`, `interruptionLevel=time-sensitive`, integer badge, typed problem deep-link data, and `refreshWidget=true`; warn payloads have default priority, no sound, passive interruption level, badge, and the same typed data. Snapshot assertions exclude Expo tokens, webhook secret paths, access tokens, and unbounded monitor metadata. |
| AC-push-mobile-widget-status-4 | PASS | The clock-controlled reliability test creates duplicate processing jobs for 28 daily critical operations and runs real queue workers for processing plus Expo/webhook delivery. It verifies critical dual-send receipts, warn push-only dispatch, duplicate receipts not inflating the 56 accepted receipt count, missing legacy pair failure, and `PushReliabilityReadModel` reporting only 27 complete days at `2026-08-07T12:00:00Z` and `retirement_ready=true` only at `2026-08-08T00:00:00Z` after 28 elapsed complete UTC days. |
| AC-push-mobile-widget-status-5 | PASS | `tests/Feature/PushNotifications/AlertingToPushEndToEndTest.php` posts real framework HTTP requests to `/__harness/alerting/results`, runs real database `queue:work`, invokes the registered `checkybot:foundation-relay`, runs another real queue worker, and observes `/__harness/push/receipts/{operation_id}` with processed listener status, accepted Expo fake delivery, accepted legacy webhook outcome, reliability recorded, and `refresh_widget=true`, without directly invoking the alerting processor, push listener, job, or channel. |

## Notes

- Migration timestamp `2026_08_06_020000_create_push_delivery_tables.php` is inside the slice-owned `2026_08_06_020000-2026_08_06_029999` range and defines reversible `down()` drops for the created push tables.
- The queue-worker requirement is satisfied for the asynchronous criteria by real `queue:work` subprocesses, not direct job invocation.

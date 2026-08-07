# External heartbeat watchdog — review

## Verdict

`review_approved`

Review round: 1. No prior review artifact exists and the milestone ledger contains no previous review/rework entries, so the rework budget is not exhausted.

## Scope reviewed

Spec path: `.full-send/canvas-runs/current/slices/alerting-reliability-core/slice-spec.json`

Spec section: `backend-external-watchdog`

Acceptance criteria reviewed:

- `AC-alerting-reliability-core-16`
- `AC-alerting-reliability-core-17`

No migrations or destructive database commands were present or run. The reviewed criteria cover scheduler/HTTP heartbeat behavior rather than an asynchronous queue/outbox seam, so the queue-worker evidence rule does not add an additional requirement for these two ACs.

## Verification rerun

Commands rerun from the parsed manifest and milestone ledger:

| Command | Exit | Evidence |
|---|---:|---|
| `./vendor/bin/pest tests/Feature/Alerting/ExternalWatchdogTest.php --compact` | 0 | 3 tests passed, 48 assertions |
| `./vendor/bin/pest --compact` | 0 | 262 tests passed, 1080 assertions |
| `./vendor/bin/phpstan analyse --no-progress --error-format=table` | 0 | PHPStan reported no errors |
| `./vendor/bin/pint --test src/Domain/Alerting tests/Feature/Alerting/ExternalWatchdogTest.php` | 0 | Pint passed |
| `find src/Domain/Alerting tests/Feature/Alerting/ExternalWatchdogTest.php -name '*.php' -print0 \| xargs -0 -n1 php -l` | 0 | All listed PHP files reported no syntax errors |
| `git diff --check` | 0 | No whitespace errors |

The ledger's recorded verification results are reproducible and truthful.

## Acceptance criteria results

### AC-alerting-reliability-core-16 — PASS

Criteria: Scheduler and HTTP-fake tests prove one watchdog GET is dispatched for each due minute to a configured HTTPS heartbeat URL with overlap prevention and bounded timeout, every 2xx records success, and scheduler registration coexists with alerting relay, retry, grouping, and maintenance-expiry entries.

Evidence:

- `tests/Feature/Alerting/ExternalWatchdogTest.php` sends four clock-controlled due-minute attempts through `checkybot:watchdog` and verifies `Http::assertSentCount(4)` against the configured HTTPS URL.
- The same test verifies 200, 201, 204, and 299 outcomes are logged as successes with matching HTTP status values.
- It verifies the outbound `User-Agent` starts with `Checkybot watchdog/`.
- It verifies bounded HTTP options by observing total and connect timeouts of `3` seconds for the configured low value.
- It verifies scheduler registration for `checkybot:watchdog` is every minute (`* * * * *`), `withoutOverlapping` is enabled, and the overlap mutex skips a pre-owned event.
- It verifies schedule descriptions contain `checkybot:foundation-relay`, `checkybot:alerting-retries`, `checkybot:alerting-groups`, `checkybot:maintenance-expire`, and `checkybot:watchdog`.
- Implementation evidence: `src/Domain/Alerting/AlertingServiceProvider.php` registers `checkybot:watchdog` every minute with `withoutOverlapping(1)` beside alerting retry/grouping schedule entries; `src/Domain/Alerting/Support/LaravelHeartbeatClient.php` performs one Laravel HTTP GET with total and connect timeouts and the watchdog user agent.
- Targeted test rerun passed: 3 tests / 48 assertions.

### AC-alerting-reliability-core-17 — PASS

Criteria: Failure-path tests prove missing configuration sends no request and reports disabled, while timeout, transport, and non-2xx responses produce only redacted local diagnostics, leave other scheduler tasks runnable, and allow a successful request on the next due minute without exposing URL credentials or query values.

Evidence:

- `tests/Feature/Alerting/ExternalWatchdogTest.php` verifies absent URL configuration returns command success, prints disabled output, logs disabled context, and `Http::assertNothingSent()`.
- The failure-path test drives timeout, transport, 503, and then 204 responses across successive minutes; it verifies failure/failure/failure/success status logging and a successful 204 on the next due minute.
- The same test verifies timeout configuration is bounded to `30` seconds when configured as `999`.
- It verifies an unrelated scheduled callback remains runnable after each watchdog outcome by checking four successful unrelated task runs.
- It serializes logger diagnostics and verifies the origin remains while URL user info, credential values, path token, and query values are absent.
- Implementation evidence: `src/Domain/Alerting/Actions/PingWatchdogHeartbeat.php` returns `WatchdogPingResult::disabled()` without calling the client when URL config is absent, catches transport failures without rethrowing, records only reason/status/origin diagnostics, and returns failure results without making `checkybot:watchdog` fail; `src/Domain/Alerting/Console/PingExternalWatchdog.php` always returns `Command::SUCCESS` so scheduler work can continue.
- Targeted test rerun passed: 3 tests / 48 assertions.

## Code quality and data integrity notes

- The implementation is small and localized: new watchdog action/client/contracts/command plus scheduler registration and targeted tests. No reviewed file crosses the 1k-line decomposition threshold.
- The injectable `HeartbeatClient` keeps transport details out of the action while preserving a direct, low-indirection flow.
- No database migrations or data-writing changes are part of this milestone; data-integrity review is not applicable beyond confirming no destructive DB commands were needed.

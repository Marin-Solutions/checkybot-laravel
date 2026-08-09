# External heartbeat watchdog — implementation ledger

## Scope

Implemented only slice section `backend-external-watchdog` for:

- `AC-alerting-reliability-core-16`
- `AC-alerting-reliability-core-17`

No migrations or database preparation were required or executed.

## Implementation

- Added alerting-local `CHECKYBOT_WATCHDOG_URL` and `CHECKYBOT_WATCHDOG_TIMEOUT` configuration.
- Added injectable `HeartbeatClient` with a Laravel HTTP implementation using one GET, a package-version user agent, and equal bounded total/connect timeouts (1–30 seconds).
- Added `PingWatchdogHeartbeat` action and `checkybot:watchdog` command. Missing configuration is an explicit disabled result. Every 2xx is success. Timeout, transport, invalid configuration, and non-2xx outcomes are caught and recorded locally without making the scheduler command fail.
- Diagnostics retain only the HTTPS origin. URL user-info, path, query, fragment, exception text, and response body are omitted.
- Registered an every-minute named scheduler event with a one-minute overlap mutex alongside foundation relay, pull retry, incident grouping, and maintenance expiry events.

## Acceptance evidence

### AC-alerting-reliability-core-16

`tests/Feature/Alerting/ExternalWatchdogTest.php` proves:

- one GET on each of four clock-controlled due minutes;
- 200, 201, 204, and 299 all record success;
- outgoing user agent is `Checkybot watchdog/<version>`;
- configured timeout reaches both total and connection request options;
- timeout values are bounded;
- the scheduler expression is every minute;
- a pre-owned mutex skips overlap; and
- watchdog, relay, retry, grouping, and maintenance-expiry schedule entries coexist.

### AC-alerting-reliability-core-17

The same test file proves:

- absent URL returns disabled and sends no request;
- timeout, transport, and 503 outcomes yield safe local failures;
- an unrelated scheduled callback remains runnable after every outcome;
- a 204 succeeds on the next due minute;
- one request only is made per attempt; and
- credentials, path token, and query values deliberately embedded in fake exception messages never appear in serialized diagnostics.

## Verification results

| Command | Exit | Result |
|---|---:|---|
| `./vendor/bin/pest tests/Feature/Alerting/ExternalWatchdogTest.php --compact` | 0 | 3 tests passed, 48 assertions |
| `./vendor/bin/pest --compact` | 0 | 262 tests passed, 1080 assertions |
| `./vendor/bin/phpstan analyse --no-progress --error-format=table` | 0 | No errors across the package |
| `./vendor/bin/pint --test src/Domain/Alerting tests/Feature/Alerting/ExternalWatchdogTest.php` | 0 | Passed |
| `php -l` over alerting PHP sources and watchdog test | 0 | No syntax errors |
| `git diff --check` | 0 | No whitespace errors |

The initial targeted test round exposed that `Http::beforeSending` was not a factory-global option probe; evidence was corrected to a real global Guzzle middleware. A subsequent assertion was corrected to serialize JSON without escaped slashes. The final targeted and full rounds above are green. Logs are retained at `build/external-watchdog-*.log`.

# Domain-expiry and p95 response-budget evaluator handoff

Outcome: completed

Rework round 1 resolves the queue-runtime review findings for AC-10 and AC-13.

Added `ExpandedChecksQueueRuntimeTest.php` and its test-only Artisan/lookup support. The runtime evidence now:

- Uses unique workspace-local SQLite files and Laravel's database queue driver.
- Runs initial domain refresh, alerting processing, +10/+30 retry dispatch, `RequestPullRecheck`, and chained alerting processing through real `queue:work` processes.
- Records fresh lookup calls at exactly +0, +10, and +30 while proving both failed retries retain the prior authoritative observation.
- Invokes all three registered scheduler target commands twice.
- Runs duplicate p95 jobs in two barrier-synchronized independent workers and drains domain refresh, domain budget, p95, and alerting jobs with real workers.
- Proves duplicate jobs yield only one operation per monitor/observation, disabled checks submit nothing, and each submitted expanded evaluation has a matching processed alerting ingestion result.

Verification:

- Expanded checks: 18 passed / 138 assertions.
- Full backend regression: 304 passed / 1598 assertions before four final targeted retention assertions; the final targeted suite passed.
- PHPStan: no errors.
- Pint and PHP syntax checks: passed.

See `ledger.md` for criterion-level evidence and command exit codes.

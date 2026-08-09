# Review: backend-release-gates-runbooks

Verdict: approved

Review round: 1 (no prior completed changes-requested review artifact found; previous attempt was transient).

## Verification rerun

- `find tests/Feature/ReleaseHardening -name '*.php' -print0 | xargs -0 -n1 php -l` — pass; all 4 PHP files reported no syntax errors.
- `./vendor/bin/pest tests/Feature/ReleaseHardening --compact` — pass; 7 tests, 188 assertions.
- `./vendor/bin/pint --test tests/Feature/ReleaseHardening` — pass.
- JSON parse check for `.full-send/canvas-runs/current/handover-checklists/v1/*.json` — pass; all 3 templates parsed.
- `git diff --check -- docs/release-hardening.md docs/watchdog-and-push-retirement.md .full-send/canvas-runs/current/handover-checklists tests/Feature/ReleaseHardening` — pass.
- `./vendor/bin/pest --compact` — pass; 357 tests, 2446 assertions.

No destructive Laravel migration/database commands were run during review.

## Acceptance criteria

| ID | Result | Evidence |
|---|---|---|
| AC-release-hardening-1 | Pass | `tests/Feature/ReleaseHardening/ReleaseGateRuntimeTest.php` starts `scripts/runtime/backend`, which verifies a run-scoped SQLite database and launches `queue:work database`; the test posts failure/success observations to `/__harness/alerting/results`, invokes the registered `checkybot:foundation-relay`, reads `/__harness/alerting/receipts/*`, and asserts no incident groups/intents and 404 push receipts. Rerun targeted Pest passed. |
| AC-release-hardening-2 | Pass | The second runtime Pest test posts multi-monitor critical and recovery samples via real harness HTTP, waits for receipts, runs the relay, reads `/__harness/push/receipts/{intent}`, and asserts one incident plus one recovery sharing a thread key with one accepted Expo delivery and accepted legacy webhook per intent, including replay dedupe. Rerun targeted Pest passed. |
| AC-release-hardening-3 | Pass | `ProvingAndFleetContractTest.php` uses a controlled Carbon clock, SQLite test DB, and production `PushReliabilityReadModel` to prove missing/stale/failed/dependent watchdog evidence, 27-day proving window, and failed/missing pairs block retirement; independent current watchdog plus 28 complete days is signable. Rerun targeted Pest passed. |
| AC-release-hardening-4 | Pass | Fleet contract test mutates the handover manifest and proves failures for missing enabled server inventory, report acceptance/schema/60-second interval/version, nginx/FPM/MySQL prerequisites and exceptions, cap, canary, scoped token rotation, rollback owner, and post-rollback heartbeat procedure. Rerun targeted Pest passed. |
| AC-release-hardening-5 | Pass | `DocumentationContractTest.php` verifies `docs/release-hardening.md`, `docs/watchdog-and-push-retirement.md`, and v1 checklist templates for executable commands, thresholds, evidence/owner/timestamp/expiry/rollback fields, Telegram generic-webhook mapping, fleet order, and secret scan rejection. JSON templates parsed successfully and targeted Pest passed. |

## Notes

The implementation stays within declared ownership: docs, handover checklist artifacts, and release-hardening tests. The asynchronous criteria are backed by the runtime harness with a real `queue:work` process rather than direct job invocation.

# Review — backend-security-outbox-runtime

Verdict: approved

Review round: 2 (prior review artifact recorded round 1 changes requested; rework budget is not exhausted).

## Verification rerun

- `node packages/contracts/scripts/verify-fixtures.mjs` — passed, 16 shared JSON-schema/TypeScript contract fixtures verified.
- `./vendor/bin/phpstan analyse --no-progress --error-format=table` — passed, no errors.
- `./vendor/bin/pest tests/Unit/MonitoringFoundation/SecretAndRedactionTest.php tests/Feature/MonitoringFoundation/SecurityOutboxRuntimeTest.php tests/Feature/MonitoringFoundation/FoundationHarnessSeamsTest.php --compact` — passed, 11 tests / 150 assertions.
- `./vendor/bin/pest --compact` — passed, 235 tests / 725 assertions.

The manifest command (`./vendor/bin/pest --compact`) and the ledger's additional verification claims are reproducible. I did not run destructive database commands; reviewed tests use Pest-managed `:memory:` SQLite or UUID-named SQLite files under `build/`.

## Acceptance criteria

| ID | Result | Evidence |
|---|---|---|
| AC-domain-runtime-foundation-6 | Pass | `tests/Unit/MonitoringFoundation/SecretAndRedactionTest.php` verifies encrypted secret/header ciphertext at rest, explicit `reveal()` restoration, masked string/JSON/debug/log output, and ProjectApiToken SHA-256-only persistence with ability, expiry, revocation, and rotation checks. Targeted Pest and full suite passed. |
| AC-domain-runtime-foundation-7 | Pass | The fixed corpus in `SecretAndRedactionTest.php` recursively covers query-string values, Authorization/Cookie keyed values and log lines, configured literals, email, IPv4, IPv6, nested arrays, and preserved diagnostic status/code fields. Targeted Pest and full suite passed. |
| AC-domain-runtime-foundation-8 | Pass | `tests/Feature/MonitoringFoundation/FoundationHarnessSeamsTest.php` posts `incident.redaction.probed` with every corpus secret through the harness API, runs `checkybot:foundation-relay`, drains an independent real database `queue:work` process, and observes the AI fake receipt plus sanitized payload through the receipt API with no original secret present. Targeted Pest and full suite passed. |
| AC-domain-runtime-foundation-9 | Pass | `tests/Feature/MonitoringFoundation/SecurityOutboxRuntimeTest.php` proves transaction rollback keeps monitor state/transition/outbox atomic, duplicate matching operation IDs create one outbox row, two barrier-synchronized relay processes enqueue one job, and the repaired retry proof creates duplicate queued jobs via relay lease recovery then uses a real database `queue:work` drain to verify attempts remain at 1 with future `available_at`; after making the event due, a fresh relay plus real worker records terminal failure metadata with secrets redacted. Targeted Pest and full suite passed. |
| AC-domain-runtime-foundation-10 | Pass | `SecurityOutboxRuntimeTest.php` verifies provider bindings for the relay command, queue job, deterministic/retry/terminal/delay fakes, minutely scheduler with overlap prevention, stopped-worker pending work/lease recovery, and production absence with testing positive control for harness routes. Targeted Pest and full suite passed. |

## Notes

The prior AC-domain-runtime-foundation-9 defect is resolved: `src/Domain/Monitoring/Foundation/Delivery/FoundationEventProcessor.php` now includes the same `available_at` due/null guard in its pending-to-processing CAS as the relay, so stale duplicate database queue messages no-op during backoff instead of processing early.

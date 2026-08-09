# Backend security/outbox runtime ledger

Milestone: `backend-security-outbox-runtime`

## Acceptance evidence

- **AC-domain-runtime-foundation-6** — `SecretAndRedactionTest.php` persists encrypted secret/header ciphertext into SQLite, restores plaintext only via `reveal()`, and proves string, JSON, debugger, and Monolog output are masked. The token test proves SHA-256-only persistence plus ability, expiry, revocation, and transactional rotation behavior.
- **AC-domain-runtime-foundation-7** — the fixed recursive corpus covers URL/query values, keyed and log-line Authorization/Cookie values, configured literals, email, IPv4, compressed IPv6, nested arrays, and preserved status/code/diagnostic fields.
- **AC-domain-runtime-foundation-8** — `FoundationHarnessSeamsTest.php` posts the complete corpus through the harness API, runs `checkybot:foundation-relay`, starts an independent real database queue worker, and reads the AI fake receipt and sanitized payload from the receipt API. No redactor or consumer is called by the test.
- **AC-domain-runtime-foundation-9** — integration tests prove transaction rollback on outbox insertion failure, idempotent duplicate operation acceptance and one delivery, retry/backoff and redacted terminal metadata. The relay race test uses two barrier-synchronized independent PHP/Testbench processes against one workspace-local SQLite file and asserts exactly one queued job. The repaired retry proof intentionally creates two queued jobs via lease recovery, drains them through a real `queue:work` process with a retryable fake, and proves the second message cannot bypass future `available_at`; after making the event due, a fresh relay plus real worker records redacted terminal metadata.
- **AC-domain-runtime-foundation-10** — container assertions cover the command, job, deterministic success/retry/terminal/delay bindings, minutely scheduler expression and overlap mutex. Database-queue tests leave stopped-worker work pending and prove lease-expiry recovery on the next relay. An isolated router runs the real route registrar with a production negative check and testing positive control.

## Runtime changes

- Added masked encrypted value objects, recursive redaction, token abilities/issuance/rotation.
- Added claim leases, due-time-aware pending-to-processing CAS delivery, deterministic fake dispatcher bindings, redacted retry/dead-letter metadata, and idempotent acceptance.
- Added testing/harness-only queue-worker fake selection so retryable and terminal outcomes are exercised in independent worker processes rather than by direct processor calls.
- Registered the overlap-safe minutely relay schedule and recovery configuration.
- Extended the owned foundation migration with claim and JSON failure metadata columns.

## Verification results

| Command | Exit | Result |
|---|---:|---|
| `node packages/contracts/scripts/verify-fixtures.mjs` | 0 | 16 shared contract fixtures verified |
| `./vendor/bin/phpstan analyse --no-progress --error-format=table` | 0 | No errors |
| `./vendor/bin/pest tests/Unit/MonitoringFoundation/SecretAndRedactionTest.php tests/Feature/MonitoringFoundation/SecurityOutboxRuntimeTest.php tests/Feature/MonitoringFoundation/FoundationHarnessSeamsTest.php --compact` | 0 | 11 tests, 150 assertions |
| `./vendor/bin/pest --compact` | 0 | 235 tests, 725 assertions |

All database tests used Pest-managed `:memory:` SQLite or UUID-named SQLite files under `build/`; no Artisan migration or destructive database command was run.

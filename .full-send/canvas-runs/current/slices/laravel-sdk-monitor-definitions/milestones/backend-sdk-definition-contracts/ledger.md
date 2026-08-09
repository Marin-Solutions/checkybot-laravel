# Backend SDK definition contracts — implementation ledger

- Milestone: `backend-sdk-definition-contracts`
- Spec section: `backend-sdk-definition-contracts`
- Round: 1
- Outcome: completed
- Database safety: no Artisan migration/database preparation commands were run. Verification used Pest/PHPUnit only; no destructive database operation was attempted.

## Acceptance-criteria evidence

### AC-laravel-sdk-monitor-definitions-1 — PASS

- Added `DomainExpiryCheck` and `ResponseTimeBudgetCheck` fluent builders, factories, getters, count/flush support, config sections, and publishable stub examples.
- `CheckSyncPayloadSerializer` applies v1 defaults (`warn_days=30`, `percentile=95`, `budget_ms=2000`) to config and fluent definitions through the same canonical serializer.
- Explicit valid thresholds and fluent/config equality are covered in:
  - `tests/Unit/Checks/DomainExpiryCheckTest.php`
  - `tests/Unit/Checks/ResponseTimeBudgetCheckTest.php`
  - `tests/Unit/ConfigValidatorTest.php`
  - `tests/Unit/CheckRegistryTest.php`
  - `tests/Unit/ConfigTest.php`

### AC-laravel-sdk-monitor-definitions-2 — PASS

- API checks now emit status, latency, and JSON-path metadata using `kind`, `operator`, optional typed `operand`/`path`, stable one-based `sort_order`, and `is_active`.
- Repeated singular status/latency configuration replaces metadata in place without changing ordering.
- Scalar strings, booleans, integers, floats, and null remain typed; no string coercion occurs.
- Evidence: `tests/Unit/Checks/ApiCheckTest.php` and canonical aggregate assertions in `tests/Unit/CheckRegistryTest.php`.

### AC-laravel-sdk-monitor-definitions-3 — PASS

- `BaseCheck` and `CheckRegistry` expose masked safe/debug/JSON/native serialization representations while retaining plaintext only in `toArray()` for the outbound request.
- Sync exception/log redaction includes configured API keys and every outbound check-header value, and transport exceptions containing request objects are not retained.
- The fixed four-value corpus test captures a real Guzzle HTTPS request and checks dry-run output, summary output, exception text, Laravel log context, `var_dump`, JSON/native serialization, snapshot data, and integration-evidence data for absence.
- Evidence: `tests/Feature/FluentApiSyncTest.php` (`AC-laravel-sdk-monitor-definitions-3`) and `tests/Unit/Checks/ApiCheckTest.php`.

### AC-laravel-sdk-monitor-definitions-4 — PASS

- `ConfigValidator` validates both registry and config definitions using `FILTER_VALIDATE_URL`, explicit HTTP(S) schemes, and `/^\d+[smhd]$/`.
- Table-driven tests cover duplicate names for all seven types, invalid URL schemes/formats, invalid intervals, domain/budget/status/latency/retry bounds, and assertion kind/operator/path/operand combinations.
- Invalid fluent API status/latency/retry values throw locally; invalid registry definitions are rejected by `validateWithRegistry` before command transport resolution.
- Evidence: `tests/Unit/ConfigValidatorTest.php`.

### AC-laravel-sdk-monitor-definitions-5 — PASS

- Existing `uptime`, `ssl`, `api`, `links`, and `openGraph` methods and interval/assertion aliases remain fluent.
- Existing options survive in canonical arrays, registry-over-config precedence remains covered by `tests/Feature/FluentApiSyncTest.php`, and all getters/count/flush behavior is covered by registry/facade tests.
- Legacy config aliases (`*_checks`, `link_checks`) are accepted and normalized to exactly `contract_version`, `uptime`, `ssl`, `api`, `dead_links`, `open_graph`, `domain_expiry`, and `response_time_budget`.

## Verification results

| Stage | Command | Result |
|---|---|---|
| Focused milestone suite | `./vendor/bin/pest tests/Unit/Checks tests/Unit/CheckRegistryTest.php tests/Unit/ConfigValidatorTest.php tests/Unit/ConfigTest.php tests/Unit/CheckybotClientTest.php tests/Unit/ServiceProviderTest.php tests/Feature/FluentApiSyncTest.php tests/Feature/SyncCommandTest.php tests/Feature/ServiceProviderTest.php --compact` | Exit 0; 194 passed, 468 assertions |
| Static analysis | `./vendor/bin/phpstan analyse --no-progress` | Exit 0; no errors |
| Formatting | `./vendor/bin/pint --test src config/checkybot-laravel.php stubs/checkybot.php.stub tests/Feature/FluentApiSyncTest.php tests/Feature/SyncCommandTest.php tests/Unit/CheckRegistryTest.php tests/Unit/CheckybotClientTest.php tests/Unit/ConfigValidatorTest.php tests/Unit/Checks tests/Unit/ConfigTest.php` | Exit 0; passed |
| Full regression suite | `./vendor/bin/pest --compact` | Exit 0; 311 passed, 1867 assertions |

## Ownership and contract note

All product/test edits are inside the declared milestone ownership. No migration or runtime evaluator behavior was added.

The checked-in foundation-owned `packages/contracts/monitor-foundation.schema.json` still defines only the previous five check arrays and lacks the two new arrays/API assertion fields. Per the slice boundary, it was not edited or copied. The mismatch was reported through Canvas message `35d011aa-7893-480d-a71f-19a4c4a5bac0`; it is a follow-up dependency for the later AC-9 contract-integration milestone, not a blocker for this definition-contract milestone.

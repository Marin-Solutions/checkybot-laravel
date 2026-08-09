# Backend API assertion builder — implementation ledger

Milestone: `backend-api-assertion-builder`  
Task: `7f8d7b31-74ef-48f6-9548-e10aedea5a98`

## Delivered

- Added the in-range `2026_08_06_040000` migration for one unique project/monitor configuration, ordered encrypted headers, ordered assertions, and optimistic versions, with cascading child cleanup and a reversible `down()`.
- Added transport-only GET, POST sample, and PUT save controller methods under the authenticated web/CSRF route group.
- Added project/API-monitor authorization before configuration or ciphertext access, including contract-specific 403/404 behavior.
- Added domain DTO/validation, transactional save action, masked read adapter, and `preserve|set|remove` semantics using the inherited `EncryptedHeaderValue` boundary.
- Added strict canonical JSONPath validation and deterministic sample path/type/preview extraction with secret-shaped response fields redacted.
- Added a bounded injectable outbound client with DNS/redirect revalidation, production SSRF denial, DNS pinning, three redirects, timeouts, a 256 KiB streamed body cap, JSON-only parsing, and typed redacted failures. The exact endpoint allowlist is honored only in `testing|harness`.
- Sample requests do not persist drafts; stored headers are revealed only while constructing the outbound request.

## Acceptance evidence

| Criterion | Evidence |
|---|---|
| `AC-web-dashboard-api-builder-4` | `ApiAssertionBuilderTest.php` exercises the migration, unique project scope, version increments, ciphertext-at-rest/query-log checks, inherited fixed mask, hidden model serialization, unchanged preserve ciphertext, explicit removal, stale 409 rollback, and direct stale/unprocessable no-create behavior. |
| `AC-web-dashboard-api-builder-5` | `ApiMonitorSampleTest.php` calls the real POST route, verifies the preserved header only on the captured outbound request, and asserts status, non-negative latency, parsed/sanitized JSON, and deterministic canonical paths/types. `BuilderValidationTest.php` independently covers catalog ordering and previews. |
| `AC-web-dashboard-api-builder-6` | `ApiMonitorSampleTest.php` proves malformed/userinfo/private/metadata targets send no request, private redirects are never followed, timeout/transport/non-JSON/401/403/body-limit failures use the declared codes and `manual_entry=true`, and credentials/upstream bodies are absent from responses and error/warning logs. |
| `AC-web-dashboard-api-builder-7` | Table-driven unit coverage validates all kinds/operators/operands and JSONPath grammar. Route-level tests preserve chained assertion order and prove duplicate/51 headers, 51 assertions, invalid combinations, stale versions, missing preserve values, and foreign access leave all configuration/header/assertion rows unchanged. |

## Verification results

| Command | Exit | Result |
|---|---:|---|
| `./vendor/bin/pest tests/Unit/ApiMonitorBuilder tests/Feature/WebDashboard/ApiAssertionBuilderTest.php tests/Feature/WebDashboard/ApiMonitorSampleTest.php --compact` | 0 | 18 passed, 143 assertions. |
| `./vendor/bin/pest tests/Feature/WebDashboard/ApiMonitorSampleTest.php --compact` | 0 | Standalone sample suite: 3 passed, 69 assertions. |
| `./vendor/bin/pest --compact` | 0 | Final full suite: 329 passed, 1880 assertions. |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | 0 | No errors. |
| `./vendor/bin/pint --test src/Domain/ApiMonitorBuilder src/Http/Controllers/ApiMonitorBuilderController.php routes/web-dashboard.php database/migrations/2026_08_06_040000_create_api_monitor_builder_tables.php tests/Unit/ApiMonitorBuilder tests/Feature/WebDashboard/ApiAssertionBuilderTest.php tests/Feature/WebDashboard/ApiMonitorSampleTest.php tests/Feature/WebDashboard/Support/ApiBuilderFixtures.php` | 0 | Passed. |

Logs: `build/backend-api-assertion-builder-pest.log`, `build/backend-api-assertion-builder-full-pest.log`, `build/backend-api-assertion-builder-phpstan.log`, and `build/backend-api-assertion-builder-pint.log`.

## Iteration notes

- The first targeted round exposed test-only strict associative-key ordering expectations and retained HTTP fake callbacks; assertions were made semantic and each failure fixture now resets its HTTP factory. The implementation behavior itself remained typed and redacted.
- Bare Testbench `route:list` did not boot package routes and returned no matching routes, so it is not used as acceptance evidence. All three routes are instead exercised through real Testbench HTTP requests in the passing feature suite.

## Database safety

Every database-backed milestone test creates a UUID-named SQLite file under workspace-local `build/`, configures that connection before running migration `up()` methods, and deletes the file afterward. No destructive Artisan database command or host service operation was run.

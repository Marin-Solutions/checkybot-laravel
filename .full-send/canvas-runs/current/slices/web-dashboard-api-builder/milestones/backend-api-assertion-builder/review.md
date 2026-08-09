# Review — backend-api-assertion-builder

Verdict: `review_approved`

Review round: 1 (no prior review artifact found).

## Verification rerun

- ✅ Manifest target: `./vendor/bin/pest tests/Unit/ApiMonitorBuilder tests/Feature/WebDashboard/ApiAssertionBuilderTest.php tests/Feature/WebDashboard/ApiMonitorSampleTest.php --compact` — 18 passed, 143 assertions.
- ✅ Ledger sample suite: `./vendor/bin/pest tests/Feature/WebDashboard/ApiMonitorSampleTest.php --compact` — 3 passed, 69 assertions.
- ✅ Ledger static/style: `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` — no errors; scoped `pint --test ...` — passed.
- ✅ Ledger full suite reproducibility: `./vendor/bin/pest --compact --order-by=default` — 329 passed, 1880 assertions.
- Note: an exact random-order `./vendor/bin/pest --compact` attempt failed once in pre-existing `ExpandedChecksQueueRuntimeTest` with one queued job remaining; rerunning that failing test standalone passed. This is recorded as a suite-order stability note, not an AC failure for this milestone.

No destructive Artisan database command was run. The reviewed tests configure UUID-named SQLite files under workspace-local `build/` directories.

## Acceptance criteria

| ID | Result | Evidence |
|---|---|---|
| AC-web-dashboard-api-builder-4 | Pass | Migration `2026_08_06_040000_create_api_monitor_builder_tables.php` is in the owned timestamp range, has reversible `down()`, a unique `project_id, monitor_id` builder configuration, ordered child tables, and cascading cleanup. `SaveBuilderConfiguration` wraps writes in a DB transaction, checks optimistic version, preserves/removes headers from existing ciphertext, increments version only on success, and `ReadBuilderConfiguration` returns `EncryptedHeaderValue` fixed masks only. Feature coverage passed in `ApiAssertionBuilderTest.php` for ciphertext at rest/query log, preserve unchanged, remove deleted, stale 409 rollback, and response/model masking. |
| AC-web-dashboard-api-builder-5 | Pass | `FetchApiSample` authorizes the API monitor, validates version/header mutations, reveals preserved headers only while constructing outbound headers, and returns status/latency/sanitized JSON plus `JsonPathCatalog` canonical path/type/preview output. `ApiMonitorSampleTest.php` passed through the real POST route and verifies the preserved Authorization header appears only on the captured outbound request, not in the response. |
| AC-web-dashboard-api-builder-6 | Pass | `EndpointPolicy` rejects malformed/userinfo/private/metadata targets before fetch, and `BoundedHttpClient` revalidates redirects, disables automatic redirects, caps redirects/time/body size, and maps timeout/transport/non-JSON/upstream 401/403/too-large failures to redacted typed errors with `manual_entry=true`. `ApiMonitorSampleTest.php` passed the unsafe endpoint, unsafe redirect, timeout, transport, non-JSON, upstream auth, and body-limit scenarios and asserts no credential/upstream body leakage to responses/logs. |
| AC-web-dashboard-api-builder-7 | Pass | `BuilderInputValidator` table-drives valid status/latency/json_path operators and rejects invalid grammar/operator/operand combinations, duplicate headers, and >50 headers/assertions. `SaveBuilderConfiguration` replaces headers/assertions transactionally after validation/version checks. Feature tests passed for ordered chained assertions, duplicate/oversized/invalid payload 422s, stale 409, foreign-project 403, missing preserve value 422, and no partial writes. |

## Code/data integrity notes

- Data constraints and transaction boundaries are adequate for the milestone: unique project/monitor configuration is enforced in the migration, header uniqueness/positions are constrained per configuration, assertion order is position-constrained, and save mutations are transactional.
- Controller remains transport-oriented; domain validation, authorization, sample fetch, JSON-path extraction, and persistence are separated under `src/Domain/ApiMonitorBuilder`.
- No queue-worker/runtime-surface evidence is required for these ACs because the milestone endpoints are synchronous and do not define an async seam.

No changes requested.

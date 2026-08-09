# Integration review — domain-runtime-foundation

Verdict: `slice_approved`

## Review basis

- Spec path: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/slice-spec.json`
- Required slice contracts: `MonitorDomainContracts` and `StatusSummaryReadModel`
- Device evidence: no device review or build-failure artifact present; recorded as no device evidence.
- Rework budget: one prior backend milestone rework round; no prior integration rework artifacts found. Budget is not exhausted.

Milestone review artifacts record pass/fail for every acceptance criterion ID: AC-1 through AC-5 in `backend-monitor-contracts/review.md`, AC-6 through AC-10 in `backend-security-outbox-runtime/review.md`, and AC-11 through AC-14 in `backend-status-summary/review.md`.

## Acceptance criteria results

| ID | Result | Integration evidence |
|---|---|---|
| AC-domain-runtime-foundation-1 | Pass | `npm --prefix packages/contracts test && npx tsc --noEmit --skipLibCheck packages/contracts/generated/monitor-foundation.ts` passed; 16 shared fixtures verified by generated TypeScript/JSON-schema tests and PHP parity is covered by `ContractParityTest.php` in the passing 17-test foundation suite. |
| AC-domain-runtime-foundation-2 | Pass | `./vendor/bin/pest tests/Feature/MonitoringFoundation tests/Unit/MonitoringFoundation --compact` passed (17 tests / 222 assertions); `PersistenceTest.php` covers current-state uniqueness, transition ordering, UUIDs, enum constraints, project scopes/indexes, and unique outbox operation IDs. |
| AC-domain-runtime-foundation-3 | Pass | Passing foundation suite includes `FoundationHarnessSeamsTest.php`; it posts a transition through `/__harness/monitor-foundation/events`, executes `checkybot:foundation-relay`, runs a real `queue:work database --stop-when-empty` process, then reads alerting and agent receipts through the receipt API. |
| AC-domain-runtime-foundation-4 | Pass | Same real harness API/relay/queue-worker seam test verifies mobile, widget, and web receipts preserve contract version, identity, state, severity, and filter. |
| AC-domain-runtime-foundation-5 | Pass | Passing seam tests post `contract.check_sync.probed` with supported check arrays through the harness, relay and drain through the real worker, observe sdk schema-valid receipt, and verify malformed/unknown payloads return 422 before enqueueing. |
| AC-domain-runtime-foundation-6 | Pass | Passing `SecretAndRedactionTest.php` coverage proves ciphertext-at-rest value objects, masked serialization/logging, and ProjectApiToken hash-only persistence with ability/expiry/revocation/rotation checks. |
| AC-domain-runtime-foundation-7 | Pass | Passing redaction corpus test recursively removes query-string values, Authorization/Cookie values, configured secret literals, emails, IPv4/IPv6, and preserves non-sensitive structure. |
| AC-domain-runtime-foundation-8 | Pass | Passing seam test posts `incident.redaction.probed` through the harness API with corpus secrets, executes relay, runs a real worker, and reads AI receipt plus sanitized payload through receipt API with no original secret. |
| AC-domain-runtime-foundation-9 | Pass | Passing `SecurityOutboxRuntimeTest.php` covers atomic domain/outbox commits, idempotent duplicate operation IDs, concurrent relay claim single-delivery, retry/backoff availability, and terminal redacted metadata; full suite also passed. |
| AC-domain-runtime-foundation-10 | Pass | Passing container/runtime tests cover provider bindings, relay command, queue job, scheduler every minute with overlap prevention, stopped-worker pending recovery, and production absence of testing-only routes. |
| AC-domain-runtime-foundation-11 | Pass | `StatusSummaryApiTest.php` in the passing suite proves `GET /api/status-summary` returns 401 for missing/invalid token, 403 for token without `status:read`, and project-scoped 200 response with declared `data.counts`, `data.updated_at`, `data.stale`. |
| AC-domain-runtime-foundation-12 | Pass | Clock-controlled `StatusSummaryApiTest.php` proves zero-filled cells, healthy/warn/down placement, recovering to warn, newest timestamp/null handling, and 900/901-second stale boundary. |
| AC-domain-runtime-foundation-13 | Pass | `StatusSummaryConsumerSeamTest.php` passes using real harness posts, registered relay, real queue worker, mobile/widget receipts through the API, and authenticated status-summary read without direct read-model or consumer invocation. |
| AC-domain-runtime-foundation-14 | Pass | `npm run harness:integration` passed with run `309afeed-65ae-4b8b-ad6a-3551c9c0c238`: built Expo web fixture, Laravel runtime, real database queue worker, relay, and Playwright. `status-summary-evidence.json` shows receipt, API, and generated web client display all match the nine counts, `updated_at`, and `stale`. |

## Contract and ownership findings

- `GET /api/status-summary` satisfies the declared token-authenticated API contract, project scoping, nine-count shape, and freshness metadata.
- Harness-only event and receipt routes are limited to testing/harness environments with loopback middleware and are absent in production per passing tests.
- Owned foundation implementation is in this package's `src/` namespace with the declared package ownership equivalents (`src/Domain/Monitoring/Foundation`, `src/Domain/Security/Foundation`, resources/controllers/models/migrations/contracts/routes/tests`). No out-of-slice product screen ownership is claimed.
- Built-surface requirement for slice integration is met by the harness `expo export` build served as a static fixture before Playwright; asynchronous seams are verified only through relay plus real `queue:work` evidence.

## Verification summary

- Contracts/type generation: pass.
- Foundation targeted Pest suite: pass, 17 / 222.
- Full Pest suite: pass, 238 / 759.
- PHPStan and whitespace: pass.
- Full-runtime built-surface Playwright harness with real queue worker: pass.

No rework requested.

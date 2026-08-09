# Backend status-summary milestone ledger

## Scope

Implemented spec section `backend-status-summary` only: project-token authentication, canonical status-summary query/resource/API, mobile/widget queue seam, generated web client, and contract-only full-runtime browser proof.

## Acceptance evidence

| Acceptance criterion | Implementation and evidence | Result |
|---|---|---|
| AC-domain-runtime-foundation-11 | `StatusSummaryApiTest.php` proves missing/invalid bearer tokens return exact 401 JSON, an active token without `status:read` returns exact 403 JSON, and a valid token reads only its own project's exact response shape. | PASS |
| AC-domain-runtime-foundation-12 | Clock-controlled `StatusSummaryApiTest.php` proves zero-filled nine-cell output, healthy/warn/down placement, recovering→warn, newest included timestamp, null freshness, exactly-900-seconds fresh, and 901-seconds stale. | PASS |
| AC-domain-runtime-foundation-13 | `StatusSummaryConsumerSeamTest.php` posts three typed transitions through the harness HTTP route, runs the registered relay, launches an independent real database queue worker, observes delivered mobile/widget receipt API entries, then authenticates through the public summary endpoint. No processor or consumer is directly invoked. | PASS |
| AC-domain-runtime-foundation-14 | Full-runtime run `68d20325-3183-43e2-aa0d-842106d8a11a` used run-scoped SQLite, Laravel's dev server, the real database queue worker, typed harness HTTP events, the registered relay CLI, generated TypeScript client, Expo contract fixture, and headless Playwright. Receipt, API, and displayed nine-cell/freshness values matched. | PASS |

## Verification results

| Command | Exit | Result |
|---|---:|---|
| `vendor/bin/pest tests/Feature/MonitoringFoundation/StatusSummaryApiTest.php --compact` | 0 | 2 tests, 12 assertions passed |
| `vendor/bin/pest tests/Feature/MonitoringFoundation/StatusSummaryConsumerSeamTest.php --compact` | 0 | 1 test, 22 assertions passed |
| `vendor/bin/pest tests/Feature/MonitoringFoundation tests/Unit/MonitoringFoundation --compact` | 0 | 17 tests, 222 assertions passed |
| `vendor/bin/pest --compact` | 0 | 238 tests, 759 assertions passed |
| `node packages/contracts/scripts/generate-types.mjs --check` | 0 | Generated TypeScript current |
| `npm run harness:test:component` | 0 | 6 tests passed |
| `npm run harness:integration` | 0 | Expo build, component suite, two Playwright journeys, worker/relay proof, and scoped cleanup passed |
| `vendor/bin/phpstan analyse --no-progress` | 0 | No errors |
| `vendor/bin/pint --test` | 0 | Formatting passed after applying the single reported model-docblock fix |
| `git diff --check` | 0 | No whitespace errors |

## Integration artifacts

- Proof: `build/harness-runs/68d20325-3183-43e2-aa0d-842106d8a11a/integration-proof.md`
- Status contract evidence: `build/harness-runs/68d20325-3183-43e2-aa0d-842106d8a11a/status-summary-evidence.json`
- Implementation capture: `build/harness-runs/68d20325-3183-43e2-aa0d-842106d8a11a/status-summary.png`
- Playwright trace/output: `build/harness-runs/68d20325-3183-43e2-aa0d-842106d8a11a/playwright`

## Database safety

No destructive database command was run. Pest tests create UUID-named SQLite files beneath workspace `build/` and remove them afterward. The runtime harness rejects non-SQLite or out-of-run-directory database paths, logs the resolved run-scoped SQLite path before its additive migration, and cleans up only its recorded child processes.

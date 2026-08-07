# Backend status-summary milestone review

- Spec path: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/slice-spec.json`
- Spec section: `backend-status-summary`
- Review round: 1 (no prior review artifact was present)
- Verdict: `review_approved`

## Acceptance criteria

| Criterion | Result | Evidence |
|---|---|---|
| AC-domain-runtime-foundation-11 | PASS | `tests/Feature/MonitoringFoundation/StatusSummaryApiTest.php` covers missing token and invalid token exact 401 JSON, active token without `status:read` exact 403 JSON, and active `status:read` token project-scoped 200 response with declared `data.counts`, `data.updated_at`, and `data.stale` shape. Re-run: `vendor/bin/pest tests/Feature/MonitoringFoundation/StatusSummaryApiTest.php --compact` passed (2 tests, 12 assertions). |
| AC-domain-runtime-foundation-12 | PASS | `StatusSummaryApiTest.php` uses `CarbonImmutable::setTestNow()` and workspace SQLite to prove zero-filled cells, healthy/warn/down placement, recovering→warn, newest included timestamp, null freshness, and the exact 900/901 second stale boundary. Same re-run passed (2 tests, 12 assertions). |
| AC-domain-runtime-foundation-13 | PASS | `tests/Feature/MonitoringFoundation/StatusSummaryConsumerSeamTest.php` posts server/website/API transitions through `POST /__harness/monitor-foundation/events`, calls the registered `checkybot:foundation-relay`, runs a separate process invoking real `queue:work --stop-when-empty`, reads mobile/widget delivery through `GET /__harness/monitor-foundation/receipts/{operation_id}`, then reads authenticated `GET /api/status-summary`. Re-run passed: `vendor/bin/pest tests/Feature/MonitoringFoundation/StatusSummaryConsumerSeamTest.php --compact` (1 test, 22 assertions). |
| AC-domain-runtime-foundation-14 | PASS | Re-ran `npm run harness:integration`; it passed with run `f1ec62dc-bd0d-4b65-9628-e1a1507d59af`. Evidence shows run-scoped SQLite (`runtime.log` database-verified path under `build/harness-runs/.../database.sqlite`), Laravel dev server, real database `queue:work` process (`worker.log` shows `DeliverFoundationEvent RUNNING/DONE` three times), typed harness HTTP posts, relay exit 0, receipt API responses delivered, generated web client display assertions, screenshot, and matching `status-summary-evidence.json` API/receipt/displayed counts, `updated_at`, and `stale`. |

## Verification re-run results

All manifest and ledger verification commands were independently re-run successfully:

- `vendor/bin/pest tests/Feature/MonitoringFoundation/StatusSummaryApiTest.php --compact` — pass, 2 tests / 12 assertions.
- `vendor/bin/pest tests/Feature/MonitoringFoundation/StatusSummaryConsumerSeamTest.php --compact` — pass, 1 test / 22 assertions.
- `vendor/bin/pest tests/Feature/MonitoringFoundation tests/Unit/MonitoringFoundation --compact` — pass, 17 tests / 222 assertions.
- `vendor/bin/pest --compact` — pass, 238 tests / 759 assertions.
- `node packages/contracts/scripts/generate-types.mjs --check` — pass, generated TypeScript current.
- `vendor/bin/phpstan analyse --no-progress` — pass, no errors.
- `npm run harness:test:component` — pass, 6 Jest tests.
- `npm run harness:integration` — pass, proof at `build/harness-runs/f1ec62dc-bd0d-4b65-9628-e1a1507d59af/integration-proof.md` and status evidence at `build/harness-runs/f1ec62dc-bd0d-4b65-9628-e1a1507d59af/status-summary-evidence.json`.
- `vendor/bin/pint --test` — pass.
- `git diff --check` — pass.

## Database and runtime safety

No destructive database command was run. The full-runtime harness initialized and migrated only a run-scoped SQLite file under the workspace: `build/harness-runs/f1ec62dc-bd0d-4b65-9628-e1a1507d59af/database.sqlite`.

# Integration verification — domain-runtime-foundation

## Scope reviewed

Spec: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/slice-spec.json`

Owned contracts verified:

- `MonitorDomainContracts`: typed monitor identity, lifecycle state, severity, transition, status-summary, encrypted secret, redaction, and outbox interfaces.
- `StatusSummaryReadModel`: authenticated `GET /api/status-summary` returning nine counts plus `updated_at`/`stale`.
- Harness-only `POST /__harness/monitor-foundation/events` and `GET /__harness/monitor-foundation/receipts/{operation_id}` seams.

## Commands run

| Command | Result | Evidence |
|---|---|---|
| `npm --prefix packages/contracts test && npx tsc --noEmit --skipLibCheck packages/contracts/generated/monitor-foundation.ts` | Pass | Generated TypeScript current; 16 shared JSON-schema/TypeScript fixtures verified. |
| `./vendor/bin/pest tests/Feature/MonitoringFoundation tests/Unit/MonitoringFoundation --compact` | Pass | 17 tests / 222 assertions passed, covering contract parity, persistence, security/redaction, outbox runtime, harness seams, and status summary. |
| `npm run harness:integration` | Pass | Run `309afeed-65ae-4b8b-ad6a-3551c9c0c238`; proof at `build/harness-runs/309afeed-65ae-4b8b-ad6a-3551c9c0c238/integration-proof.md`. |
| `./vendor/bin/phpstan analyse --no-progress --error-format=table && git diff --check` | Pass | PHPStan reported no errors; whitespace check passed. |
| `./vendor/bin/pest --compact` | Pass | 238 tests / 759 assertions passed. |

## Full-runtime evidence

Harness integration run: `309afeed-65ae-4b8b-ad6a-3551c9c0c238`

- Built surface: `npm run harness:frontend:build` executed `expo export e2e/harness-fixture --platform web --output-dir ../../build/harness-fixture --clear` and exited 0 before Playwright.
- Backend surface: `scripts/runtime/backend start` started Laravel on `http://127.0.0.1:36169` with run-scoped SQLite.
- Queue worker: `worker.log` shows a real database `queue:work` process running `Checkybot\Harness\ProcessQueueProbe` and three `MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Jobs\DeliverFoundationEvent` jobs to DONE.
- Browser journey: `npm run harness:test:browser` passed 2 Playwright tests, including generated web client display of relayed status summary.
- Status-summary contract evidence: `build/harness-runs/309afeed-65ae-4b8b-ad6a-3551c9c0c238/status-summary-evidence.json` shows server down, website recovering-as-warn, and API healthy counts match between receipt status summaries, authenticated API response, and displayed web client values, with `stale=false`.
- Queue proof: `probe-evidence.json` and proof document show `POST 202`, `GET 200`, accepted and processed timestamps, and no direct job/processor invocation as evidence.

## API contract verification

| Contract route | Verification result |
|---|---|
| `GET /api/status-summary` | Pass. `StatusSummaryApiTest.php` verifies missing/invalid bearer token 401, valid token without `status:read` 403, and scoped 200 response shape. Full-runtime evidence verifies generated web client consumes the authenticated response and displays the same nine counts, `updated_at`, and `stale` from receipts/API. |
| `POST /__harness/monitor-foundation/events` | Pass. Feature seam tests and full-runtime Playwright post typed transition events through the real HTTP harness endpoint; malformed/unknown check-sync payloads return 422 before enqueueing in targeted tests. |
| `GET /__harness/monitor-foundation/receipts/{operation_id}` | Pass. Feature seam tests and harness evidence read delivered alerting, mobile, widget, web, agent, sdk, and ai fake-consumer effects only after relay plus real queue worker. |

## Runtime/database safety

No destructive database command was run. Harness runtime log records `[stage=database-verified] connection=sqlite database=/home/ploi/workspaces/agent-canvas-b1631f73-b32f-4463-ae9b-0633a2a40625-checkybot-laravel/build/harness-runs/309afeed-65ae-4b8b-ad6a-3551c9c0c238/database.sqlite` before additive migration execution.

## Device evidence

No device evidence files were present for this foundation/backend slice. This is recorded as `no device evidence`; no slice blocking finding is made for missing device infrastructure.

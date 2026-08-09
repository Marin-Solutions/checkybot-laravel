# Integration verification — agent-v2-expanded-monitors

Spec path: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/slice-spec.json`

## Commands rerun during integration review

| Command | Result | Evidence |
|---|---:|---|
| `npm run harness:integration` | PASS | Built Expo web harness with `expo export` into `build/harness-fixture`, started guarded run-scoped SQLite backend, real database `queue:work`, static fixture server, component tests, and Playwright browser tests. Proof: `build/harness-runs/fb00df90-958e-41e4-8cf9-222375a97593/integration-proof.md`; queue probe POST 202 / GET 200; worker log path recorded there. |
| `node tests/Feature/AgentV2/prepare-agent-evaluator-runtime.mjs && npx playwright test --config=tests/Feature/AgentV2/playwright.config.ts` | PASS | Slice seam proof rerun: guarded backend on run-scoped SQLite, real queue worker pid `543201`, three authenticated `POST /api/v2/agent-reports`, registered evaluator command, foundation relay, and receipt HTTP reached processed `down`. Evidence: `build/agent-evaluator-playwright/evidence.json`, `build/agent-evaluator-playwright/runtime.json`. |
| `./vendor/bin/pest --compact` | PASS | Full regression passed: 308 tests / 1653 assertions. |

## Runtime and database safety

No destructive database commands were run. The only Artisan migration observed during this review was the guarded harness backend's additive `migrate --force`; `scripts/runtime/backend` first verifies `HARNESS_DB_CONNECTION=sqlite` and a database path under the run directory (`build/harness-runs/<uuid>/database.sqlite`) before migrating. No Redis/server/Supervisor/critical host service or host package command was used. No Mimir lane guard refusal occurred.

## Production-shaped surface evidence

The slice owns no product frontend screens, but this slice-integration review still reran the production-shaped harness surface. `npm run harness:integration` executed `npm run harness:frontend:build` (`expo export ... --platform web --output-dir ../../build/harness-fixture --clear`), served the generated web output through `scripts/runtime/frontend`, and drove it with headless Playwright against the real backend and queue worker.

## API contract integration checks

- `POST /api/v2/agent-reports`: milestone reviews and rerun Playwright prove authenticated HTTP ingest, 202 queued responses, deterministic evaluation operation IDs, persisted reports before queue processing, and receipt observation after registered evaluator + queue worker + relay.
- `MonitorResultIngestionInterface::ingest`: server, domain-expiry, response-budget, and dead-man evaluators submit normalized results through the inherited ingestion interface; runtime evidence demonstrates alerting processing through queue/outbox rather than direct lifecycle mutation.
- `DomainExpiryLookup::lookup`: milestone runtime tests prove canonical domain lookup, retained prior observations on failures, and real lookup calls on +10/+30 retry flow via `queue:work`.
- `RedactedLogSnippetProvider::forIncident`: provider contract and side-effect tests prove opt-in authorization, redaction, bounded reads, `alert_eligible=false`, and no alerting side effects.
- Harness receipt route: verified only through canonical harness paths during runtime tests.

# Integration review — agent-v2-expanded-monitors

Verdict: `slice_approved`

Spec path used: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/slice-spec.json`

## Review summary

All milestone review artifacts record pass/fail rows for their acceptance criteria, and all 16 criteria are passing. I reran the full Pest regression, the canonical built-surface harness integration, and the slice-owned agent-to-alerting Playwright seam with a real `queue:work` process. No backend or frontend rework findings remain.

Device evidence: no device review or build-failure file is present in this slice directory; recorded as no device evidence and not blocking.

Rework budget: one prior milestone rework round was recorded for AC-10/AC-13; this is below the third-round exhaustion threshold.

## Acceptance criteria results

| ID | Result | Integration-review evidence |
|---|---|---|
| AC-agent-v2-expanded-monitors-1 | PASS | Milestone review `backend-agent-v2-report-runtime/review.md` records fixture collector coverage for baseline, second-run, hot-plug, reset, interrupted atomic replacement, semantic versioning, unavailable baseline/reset data, and mode-0600 state. Full regression rerun passed. |
| AC-agent-v2-expanded-monitors-2 | PASS | Milestone review records API feature coverage for 401/403/422/202/200/409 plus real queue-worker evidence for persisted report/job and idempotent replay. The slice Playwright rerun again posted authenticated v2 reports over HTTP and observed queued receipts. |
| AC-agent-v2-expanded-monitors-3 | PASS | Milestone review records parser/persistence tests for FPM/nginx/prerequisites and redaction rejection/non-persistence of query values, auth/cookie data, secrets, email, and IP values. Full regression rerun passed. |
| AC-agent-v2-expanded-monitors-4 | PASS | Milestone review records default 1 Gbit/s cap, `share_redacted_logs=false`, project-isolated cap overrides, invalid override rejection, and real worker evaluation using the override. |
| AC-agent-v2-expanded-monitors-5 | PASS | Milestone review records table-driven CPU/RAM/disk/network thresholds, predictive disk, baseline/reset exclusion, persisted cap use, and one deterministic worst-band server result. Full regression rerun passed. |
| AC-agent-v2-expanded-monitors-6 | PASS | Milestone review records FPM utilization/max-children boundary tests and misconfigured-source handling without invented zero-worker health. |
| AC-agent-v2-expanded-monitors-7 | PASS | Milestone review records nginx 5xx boundary tests, low-volume fallback, timeout critical candidate, and missing/disabled/permission-denied access log misconfiguration behavior. |
| AC-agent-v2-expanded-monitors-8 | PASS | Milestone review records clock-controlled dead-man 179/180/299/300s behavior, duplicate-scan idempotency, fresh-report reset, out-of-order rejection, and concurrency coverage. |
| AC-agent-v2-expanded-monitors-9 | PASS | Rerun `node tests/Feature/AgentV2/prepare-agent-evaluator-runtime.mjs && npx playwright test --config=tests/Feature/AgentV2/playwright.config.ts` passed. Evidence JSON shows three authenticated HTTP reports, registered evaluator command, real queue worker pid `543201`, relay exit 0, receipt status `processed`, current state `down`, and healthy→warn→down transitions. |
| AC-agent-v2-expanded-monitors-10 | PASS | Milestone review round 2 records real-worker retry evidence: daily refresh, alerting retries at +10/+30, `RequestPullRecheck`, and chained alerting processing through database queue workers with fresh lookup calls and retained prior observation on failures. |
| AC-agent-v2-expanded-monitors-11 | PASS | Milestone review records clock-controlled domain-expiry bands (>30 healthy, exactly 30 through expiry warn, expired critical), stale/absent unavailable behavior, deterministic operation IDs, and project identity. |
| AC-agent-v2-expanded-monitors-12 | PASS | Milestone review records nearest-rank p95 tests for target/check isolation, finite successful samples only, 2000/2001 ms boundary, tail behavior, and invalid/stale/cross-project exclusions. |
| AC-agent-v2-expanded-monitors-13 | PASS | Milestone review records daily/minute schedule inspection, overlap safety, duplicate job idempotency, disabled-check no-op behavior, and real queue-worker proof that accepted results use ingestion rather than direct state/transition mutation. |
| AC-agent-v2-expanded-monitors-14 | PASS | Milestone review records provider contract tests for default-disabled, unauthorized/cross-project/source/window denials, bounded ordered opt-in results, and second-pass redaction. |
| AC-agent-v2-expanded-monitors-15 | PASS | Milestone review records side-effect tests showing provider calls leave monitor state, transitions, incidents, outbox, and notification intents unchanged and always return `alert_eligible=false`. |
| AC-agent-v2-expanded-monitors-16 | PASS | Milestone review records documentation contract coverage for executable rollout/preflight/verification steps across log access, permissions, FPM/MySQL, state mode, caps, token rotation, canary, inventory, rollback, and all prerequisite remediation states. |

## Integrated contract and ownership review

- API contract: `POST /api/v2/agent-reports` is routed through `routes/agent.php` with bearer token middleware and a thin controller. Runtime evidence proves authenticated HTTP ingest and alerting receipt reads through registered commands/jobs and the real worker.
- Alerting seam: agent, dead-man, domain-expiry, and response-budget evaluators call `MonitorResultIngestionInterface` with normalized results. The accepted runtime evidence does not invoke evaluator/ingestion processors directly for AC-9.
- Redacted log provider: implemented as an opt-in read provider with no alert-creation side effects; provider tests verify the `ai-incident-annotations` boundary.
- Domain lookup/response budget: registered commands, schedules, jobs, and retry producer are covered by milestone runtime tests with real queue workers.
- Declared ownership: implementation is within package-path equivalents of the slice-owned `agent`, `src/Domain/Agent`, `src/Domain/ExpandedChecks`, controller, routes, docs, tests, and assigned migration range. No product frontend screen is owned or added by this backend slice.

## Data integrity and migration review

Migrations `2026_08_06_030000`, `2026_08_06_031000`, and `2026_08_06_032000` are inside the declared `2026_08_06_030000-2026_08_06_039999` range and include `down()` methods. They add new tables with defaults for existing-row safety, unique operation IDs/idempotency constraints, server/report/evaluation uniqueness where required, and FK cascade choices scoped to agent-owned data. Queue dispatch after report persistence uses `afterCommit`.

## Verification commands

See `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/integration-verification.md` for rerun details. Key results:

- `npm run harness:integration` — PASS with built Expo web output, real backend, real queue worker, and Playwright.
- `node tests/Feature/AgentV2/prepare-agent-evaluator-runtime.mjs && npx playwright test --config=tests/Feature/AgentV2/playwright.config.ts` — PASS.
- `./vendor/bin/pest --compact` — PASS, 308 tests / 1653 assertions.

No destructive database commands, host service controls, Supervisor/Redis controls, or host package installs were run. No Mimir lane guard refusal occurred.

## Findings

No changes requested.

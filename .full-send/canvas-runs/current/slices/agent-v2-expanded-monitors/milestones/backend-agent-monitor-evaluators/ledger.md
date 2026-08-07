# Ledger — Server metric, log, prerequisite, and dead-man evaluators

Milestone: `backend-agent-monitor-evaluators`  
Outcome: completed

## Implemented

- Added one aggregate server evaluator with persisted details and deterministic report operation IDs.
- Added CPU, RAM, disk/predictive-full, max-direction network/cap, FPM, nginx, and prerequisite rules.
- Added monotonic persisted server liveness, dead-man evaluation, due command, queued report jobs, and overlap-safe one-minute schedule.
- Added evaluator/liveness persistence in reversible migration `2026_08_06_031000` (inside owned range).
- Registered `AgentServiceProvider` and retained all alerting changes behind `MonitorResultIngestionInterface`.
- Added the canonical run-scoped SQLite + real database queue worker Playwright journey.

## Acceptance evidence

### AC-agent-v2-expanded-monitors-5

- `tests/Unit/AgentV2/ServerMetricEvaluatorTest.php` table-drives CPU 85/95, RAM 85/95, disk 80/90, exactly seven predictive days, and network 70/90 using `max(rx, tx)`.
- Baseline/reset samples are excluded and their count is persisted in evaluation details.
- `tests/Feature/AgentV2/AgentMonitorEvaluatorTest.php` proves one deterministic aggregate row/result, persisted cap use, and critical network dominance over healthy siblings.

### AC-agent-v2-expanded-monitors-6

- Unit coverage proves FPM 79% healthy, 80% warn, 100% critical, and `max_children_reached_5m > 0` critical.
- Missing, denied, and disabled status sources become persisted `misconfigured` details and bounded prerequisite reason codes; no zero-worker health is synthesized.

### AC-agent-v2-expanded-monitors-7

- Unit coverage proves exact 1% healthy, above 1% warn, above 5% critical, low-volume 9 healthy/10 critical, and any upstream timeout critical.
- Correlated timeout totals are retained in details. Missing/denied/disabled access logs persist `misconfigured`, submit warning candidates, and omit a fabricated rate.

### AC-agent-v2-expanded-monitors-8

- `tests/Feature/AgentV2/AgentDeadManTest.php` uses a controlled clock for 179/180/299/300 second boundaries, duplicate scan idempotency, fresh-report reset, and monotonic rejection of an out-of-order liveness timestamp.
- `tests/Feature/AgentV2/AgentDeadManConcurrencyTest.php` launches two barrier-synchronized independent PHP processes against workspace-local SQLite; one dead-man evaluation, one alerting operation, and one queue job survive the overlap.
- Command/schedule coverage proves the one-minute `checkybot:agent-evaluate-due` registration, `withoutOverlapping`, and `onOneServer`.

### AC-agent-v2-expanded-monitors-9

- `tests/Feature/AgentV2/AgentEvaluatorRuntime.spec.ts` posts three authenticated 95%-of-cap reports through HTTP, executes the registered evaluator command, waits on the real database queue worker, executes the foundation relay, and reads the harness receipt through HTTP.
- Evidence: `build/agent-evaluator-playwright/evidence.json` records three deterministic evaluation IDs, processed status, canonical `down`, healthy→warn→down transitions, relay exit 0, and transition consumer receipts.
- No evaluator, ingestion action, alert processor, or consumer is invoked directly by the Playwright journey.

## Verification results

| Command | Result |
|---|---|
| `vendor/bin/pest tests/Unit/AgentV2 tests/Feature/AgentV2 --compact` | exit 0 — 16 tests, 238 assertions |
| `vendor/bin/pest --compact` | exit 0 — 286 tests, 1464 assertions |
| `vendor/bin/pint --test src/Domain/Agent src/CheckybotLaravelServiceProvider.php tests/Unit/AgentV2 tests/Feature/AgentV2 database/migrations/2026_08_06_031000_create_agent_monitor_evaluator_tables.php scripts/harness/seed-status-summary.php` | exit 0 |
| `node tests/Feature/AgentV2/prepare-agent-evaluator-runtime.mjs` | exit 0 — guarded run-scoped SQLite backend and real queue worker ready |
| `npx playwright test --config=tests/Feature/AgentV2/playwright.config.ts` | exit 0 — 1 test passed; owned backend/worker children stopped afterward |
| `vendor/bin/phpstan analyse src/Domain/Agent --no-progress` | informational exit 1 — no source diagnostics; repository config fails on an unmatched pre-existing `larastan.noEnvCallsOutsideOfConfig` ignore pattern |

## Database and host safety

No destructive database command was run. Pest uses in-memory or per-test workspace SQLite. The browser runtime used the guarded `scripts/runtime/backend` launcher, which verified SQLite at `build/harness-runs/<run-uuid>/database.sqlite` before additive migration. No host services, Redis, Supervisor, or host packages were touched.

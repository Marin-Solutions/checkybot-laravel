# Review — Server metric, log, prerequisite, and dead-man evaluators

Verdict: **approved**  
Outcome: `review_approved`  
Review round: 1; no prior review artifact existed, so the rework budget is not exhausted.

## Acceptance criteria

| Criterion | Result | Evidence |
|---|---:|---|
| AC-agent-v2-expanded-monitors-5 | PASS | `tests/Unit/AgentV2/ServerMetricEvaluatorTest.php` covers CPU 85/95, RAM 85/95, disk 80/90, predictive full at 7 days, network 70/90 using max(rx,tx), and baseline/reset exclusion. `tests/Feature/AgentV2/AgentMonitorEvaluatorTest.php` proves a single deterministic aggregate evaluation/ingestion operation and persisted server cap dominance over healthy sibling metrics. Implementation evidence: `EvaluateServerMetrics` selects max network direction and worst candidate; `PrepareAgentReportEvaluation` creates one uuid5 server aggregate per report. Targeted Pest passed: 16 tests, 238 assertions. |
| AC-agent-v2-expanded-monitors-6 | PASS | `tests/Unit/AgentV2/ServerMetricEvaluatorTest.php` covers FPM below 80 healthy, 80 warn, 100 critical, `max_children_reached_5m > 0` critical with bounded reason code, and missing/permission_denied/disabled status as misconfigured warn candidates. `PrepareAgentReportEvaluation` persists evaluator details into `agent_monitor_evaluations.details`. Targeted Pest passed. |
| AC-agent-v2-expanded-monitors-7 | PASS | `tests/Unit/AgentV2/ServerMetricEvaluatorTest.php` covers exactly 1% healthy, >1% warn, >5% critical, low-volume 9 healthy/10 critical, upstream timeout critical, and missing/permission_denied/disabled access logs as misconfigured without a fabricated rate. Implementation records correlated timeout-inclusive 5xx totals. Targeted Pest passed. |
| AC-agent-v2-expanded-monitors-8 | PASS | `tests/Feature/AgentV2/AgentDeadManTest.php` covers 179/180/299/300 second boundaries, duplicate scan idempotency, fresh report reset, and out-of-order timestamp monotonicity. `tests/Feature/AgentV2/AgentDeadManConcurrencyTest.php` runs two barrier-synchronized PHP processes against workspace-local SQLite and proves one dead-man evaluation, one alerting result, and one queued job. `tests/Feature/AgentV2/AgentMonitorEvaluatorTest.php` verifies command registration and `withoutOverlapping`/`onOneServer` schedule. Targeted Pest passed. |
| AC-agent-v2-expanded-monitors-9 | PASS | Canonical Playwright runtime passed. The test posts three authenticated high-network reports over HTTP, invokes `checkybot:agent-evaluate-due`, relies on a real `queue:work` process launched by `scripts/runtime/backend`, invokes the foundation relay, and reads `GET /__harness/alerting/receipts/{operation_id}` over HTTP. `build/agent-evaluator-playwright/evidence.json` records three evaluation operation IDs, real queue worker pid `509188`, processed receipt, current_state `down`, healthy→warn→down transitions, and relay exit 0. No evaluator/ingestion processor/consumer is directly invoked by the Playwright test. |

## Verification rerun

| Command | Result |
|---|---:|
| `vendor/bin/pint --test src/Domain/Agent src/CheckybotLaravelServiceProvider.php tests/Unit/AgentV2 tests/Feature/AgentV2 database/migrations/2026_08_06_031000_create_agent_monitor_evaluator_tables.php scripts/harness/seed-status-summary.php` | PASS |
| `vendor/bin/pest tests/Unit/AgentV2 tests/Feature/AgentV2 --compact` | PASS — 16 tests, 238 assertions |
| `node tests/Feature/AgentV2/prepare-agent-evaluator-runtime.mjs` | PASS — launched run-scoped SQLite runtime and real queue worker |
| `npx playwright test --config=tests/Feature/AgentV2/playwright.config.ts` | PASS — 1 test passed |
| `vendor/bin/pest --compact` | PASS — 286 tests, 1464 assertions |
| `vendor/bin/phpstan analyse src/Domain/Agent --no-progress` | Informational FAIL matching ledger: no source diagnostics; phpstan exits on pre-existing unmatched ignored-error pattern `larastan.noEnvCallsOutsideOfConfig` in `config/*`. Not part of the manifest acceptance gate. |

## Data and runtime safety

- No destructive database command was run.
- The runtime launcher verified `HARNESS_DB_CONNECTION=sqlite` and a database path under the per-run `build/harness-runs/<uuid>/database.sqlite` before running additive `migrate --force`.
- Migration `2026_08_06_031000_create_agent_monitor_evaluator_tables.php` is inside the owned timestamp range, reversible via `down()`, and adds new tables with unique/FK constraints for liveness and evaluation idempotency.
- No host services, Redis, Supervisor, or system packages were controlled.

## Findings

No changes requested.

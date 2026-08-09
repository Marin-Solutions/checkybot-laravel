# Review — Stateful agent v2 collection and report ingestion

Verdict: `review_approved`

Review round: 1 (no prior review artifact found; rework budget not exhausted).

## Acceptance criteria

| ID | Result | Evidence |
|---|---|---|
| AC-agent-v2-expanded-monitors-1 | Pass | `tests/Unit/AgentV2/CollectorTest.php` covers baseline, second-run, hot-plug, reset, and interrupted atomic replacement. Re-run targeted suite passed: `./vendor/bin/pest tests/Unit/AgentV2 tests/Feature/AgentV2 --compact` → 9 passed / 151 assertions. Code evidence in `agent/src/AgentV2Collector.php` and `AtomicStateStore` emits `agent-report.v2`, semantic `2.0.0`, null baseline/reset deltas, elapsed ready deltas, flocked update, atomic rename, and 0600 state/lock files. |
| AC-agent-v2-expanded-monitors-2 | Pass | `tests/Feature/AgentV2/AgentReportApiTest.php` proves 401/403/422/202/200/409 behavior, child-row persistence, and duplicate/collision handling. I also verified the async seam with a real harness server and real `queue:work database`: run-scoped SQLite was logged as `[stage=database-verified] connection=sqlite database=.../build/harness-runs/9283f2bc.../database.sqlite`; POST `/api/v2/agent-reports` returned 202 queued; DB poll showed `reports=1`, `network_samples=1`, `jobs` went from 1 to 0, and worker log showed `EvaluateAgentReport RUNNING` then `DONE`; identical replay returned 200 duplicate with final `reports=1`, no queued job. |
| AC-agent-v2-expanded-monitors-3 | Pass | `tests/Unit/AgentV2/LogParserTest.php` verifies PHP-FPM max_children and nginx five-minute aggregate parsing; `AgentReportApiTest.php` verifies FPM/window/prerequisite persistence and 422 rejection/non-persistence for unredacted query values, Authorization/Cookie credentials, configured secret, email, and IP data. Targeted suite passed. Code evidence: `StoreAgentReportRequest::validateRedaction()` and `IngestAgentReport` re-redact payload/prerequisites/log lines before persistence. |
| AC-agent-v2-expanded-monitors-4 | Pass | Migration defaults set `link_cap_bps` to `1_000_000_000` and `share_redacted_logs` false; `RegisteredServer::register()` relies on these defaults. `OverrideServerLinkCap` rejects non-positive and cross-project overrides, covered in `AgentReportApiTest.php`. Real worker verification above seeded a server with `link_cap_bps=2500000000`; after actual `queue:work` processed `EvaluateAgentReport`, `agent_reports.evaluation_link_cap_bps` was `2500000000`, proving the next queued evaluation uses the override without direct job invocation. |

## Verification rerun

| Command | Result |
|---|---|
| `./vendor/bin/pest tests/Unit/AgentV2 tests/Feature/AgentV2 --compact` | Pass — 9 passed, 151 assertions |
| `./vendor/bin/pest --compact` | Pass — 279 passed, 1377 assertions |
| `./vendor/bin/pint --test src/Domain/Agent src/Http/Controllers/AgentReportController.php routes/agent.php database/migrations/2026_08_06_030000_create_agent_v2_report_runtime_tables.php tests/Feature/AgentV2 tests/Unit/AgentV2 agent/src` | Pass |
| PHP lint across owned PHP files | Pass — no syntax errors |
| `./vendor/bin/phpstan analyse src/Domain/Agent src/Http/Controllers/AgentReportController.php --no-progress` | Exit 1 matching ledger: only unmatched ignored-error pattern `larastan.noEnvCallsOutsideOfConfig`; no source diagnostics |

## Database/runtime safety

No destructive database commands were run. The only Artisan migration was the existing harness `migrate --force` inside `scripts/runtime/backend`, after that script verified a run-scoped SQLite file under `build/harness-runs/<uuid>/database.sqlite`. The harness app and worker were stopped after review verification.

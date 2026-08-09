# Backend Agent v2 Report Runtime Ledger

Milestone: `backend-agent-v2-report-runtime`  
Task: `8f4b944c-77f5-4306-b951-c0609a07a866`  
Date: 2026-08-07

## Scope delivered

- Added the fixture-capable `agent-report.v2` collector under `agent/`, including semantic agent versioning, real elapsed rx/tx deltas, baseline/hot-plug/reset handling, an exclusive state lock, atomic replacement, and mode-0600 state/lock files.
- Added static PHP-FPM/nginx parsers and local redaction for query values, authorization/cookies, configured secrets, email addresses, and IP addresses.
- Added the authenticated `POST /api/v2/agent-reports` route, thin controller, strict recursive payload validation, token/project/server authorization, immutable canonical idempotency, transactional report/sample persistence, and after-commit evaluator dispatch.
- Added registered-server settings with a 1 Gbit/s default link cap, disabled log sharing by default, project-isolated positive cap overrides, and evaluation-time cap lookup.
- Added the reversible migration `2026_08_06_030000_create_agent_v2_report_runtime_tables.php` inside the assigned timestamp range.

## Acceptance evidence

| Criterion | Evidence |
|---|---|
| `AC-agent-v2-expanded-monitors-1` | `tests/Unit/AgentV2/CollectorTest.php` drives baseline, second-run, hot-plug, reset, and interrupted replacement fixtures. It verifies exact deltas/elapsed rates, unavailable baseline/reset data, valid JSON after interruption, and 0600 state mode. |
| `AC-agent-v2-expanded-monitors-2` | `tests/Feature/AgentV2/AgentReportApiTest.php` verifies 401/403/422/202/200/409 behavior, strict malformed payload cases, all child-row persistence, one queued job, immutable replay, and no duplicate rows/jobs. |
| `AC-agent-v2-expanded-monitors-3` | `tests/Unit/AgentV2/LogParserTest.php` verifies five-minute FPM/nginx aggregates and local redaction. Feature persistence assertions verify pool/window/prerequisite fidelity and rejection/non-persistence of sensitive lines. |
| `AC-agent-v2-expanded-monitors-4` | Feature settings coverage verifies both defaults, positive project-scoped override, zero/negative/cross-project rejection, and that the queued evaluation resolves the current override. |

## Verification results

| Command | Exit | Result |
|---|---:|---|
| `./vendor/bin/pest tests/Unit/AgentV2 tests/Feature/AgentV2 --compact` | 0 | 9 passed, 151 assertions |
| `./vendor/bin/pest --compact` | 0 | 279 passed, 1377 assertions |
| `./vendor/bin/pint --test src/Domain/Agent src/Http/Controllers/AgentReportController.php routes/agent.php database/migrations/2026_08_06_030000_create_agent_v2_report_runtime_tables.php tests/Feature/AgentV2 tests/Unit/AgentV2 agent/src` | 0 | Formatting passed after applying Pint once. |
| PHP syntax checks over all owned PHP files | 0 | No syntax errors. |
| `./vendor/bin/phpstan analyse src/Domain/Agent src/Http/Controllers/AgentReportController.php --no-progress` | 1 | No source diagnostics remained; repository PHPStan configuration fails because its existing `larastan.noEnvCallsOutsideOfConfig` ignored-error pattern matches no reported error. Not declared in the verification manifest. |

## Database safety

No Artisan migration command or destructive database command was run. Agent feature tests create isolated SQLite `:memory:` connections and invoke only the two required migration objects inside the test process.

# Backend expanded website evaluators — verification ledger

## Scope

Implemented spec section `backend-expanded-website-evaluators` only, covering AC-agent-v2-expanded-monitors-10 through AC-agent-v2-expanded-monitors-13.

## Rework round 1

Addressed the review finding that prior retry and scheduler tests bypassed Laravel's queue runtime. `ExpandedChecksQueueRuntimeTest.php` now creates a unique workspace-local SQLite database, invokes the registered scheduler target commands in independent Artisan processes, and processes their jobs with real `queue:work --stop-when-empty` processes using the database queue driver (never the sync driver).

## Acceptance evidence

| Acceptance criterion | Evidence |
|---|---|
| AC-10 | Existing adapter and feature tests prove IDNA canonicalization, authoritative RDAP persistence, bounded failures, and failed-refresh retention. The new queue-runtime test runs the initial daily refresh job and its alerting `ProcessMonitorResult` job through `queue:work`, dispatches due retries with the registered `checkybot:alerting-retries` command at controlled +10 and +30 clocks, then runs `RequestPullRecheck` and chained processing through fresh workers. A cross-process file-backed deterministic adapter records exactly three actual lookup calls at 12:00:00, 12:00:10, and 12:00:30; both failures retain the prior WHOIS observation and the third authoritative RDAP success replaces it. |
| AC-11 | Clock-controlled feature cases prove >30 whole days healthy, exactly 30 through expiry warn, post-expiry critical, stale/absent unavailable without healthy ingestion, deterministic UUIDs, and persisted project-scoped website identity. |
| AC-12 | Target/project/status/age filtered stored-speed reads and nearest-rank tests prove 2000 ms healthy, 2001 ms warn, a 19-sample extreme tail sets p95, and empty/null/failed/stale/cross-project samples remain unavailable. |
| AC-13 | Schedule inspection proves daily/one-minute cadence plus `withoutOverlapping` and `onOneServer`. The runtime invokes each of the three registered scheduler targets twice, producing duplicate database-queue jobs only for enabled checks. Two barrier-synchronized independent `queue:work` processes race duplicate p95 jobs on separate queues and produce one reserved evaluation and one alerting ingestion row. A real default worker drains duplicate daily refresh/domain-budget jobs and all chained alerting jobs. Final evidence is exactly three submitted evaluations, exactly three matching processed `alerting_results`, one real domain lookup, no disabled-check evaluation, no direct state/transition writes before worker processing, and an empty jobs table. |

## Verification runs

| Command | Exit | Result |
|---|---:|---|
| `./vendor/bin/pest tests/Feature/ExpandedChecks/ExpandedChecksQueueRuntimeTest.php --compact` | 0 | 2 real-worker runtime tests passed / 48 assertions before four additional retention assertions; the expanded suite below includes the final assertions. |
| `./vendor/bin/pest tests/Unit/ExpandedChecks tests/Feature/ExpandedChecks --compact` | 0 | 18 passed / 138 assertions, including real database queue workers and barrier-synchronized independent worker processes. |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | 0 | No errors. |
| `./vendor/bin/pint --test tests/Feature/ExpandedChecks` | 0 | Formatting passed. |
| `find tests/Feature/ExpandedChecks -name '*.php' -print0 \| xargs -0 -n1 php -l && php -l tests/Feature/ExpandedChecks/Support/expanded-artisan` | 0 | All expanded-check test/runtime PHP parsed successfully. |
| `./vendor/bin/pest --compact` | 0 | Full regression passed: 304 tests / 1598 assertions (run immediately before the final four targeted retention assertions; final targeted suite then passed). |

## Database safety

No Artisan migration command and no destructive database command was run. Pest/Testbench used its test connection. Each real-worker runtime test explicitly creates a unique SQLite file under `build/expanded-check-runtime-tests/`, configures both parent and child processes with `DB_CONNECTION=sqlite`, verifies the child-resolved path is inside the workspace before starting Artisan, and removes the file afterward.

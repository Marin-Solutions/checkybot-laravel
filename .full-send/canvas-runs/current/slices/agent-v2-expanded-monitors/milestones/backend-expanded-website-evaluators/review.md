# Review — backend-expanded-website-evaluators

Verdict: approved

Review round: 2. Prior review requested changes for AC-agent-v2-expanded-monitors-10 and AC-agent-v2-expanded-monitors-13 because retry/scheduler evidence bypassed real `queue:work`. The rework ledger adds real-worker runtime evidence, and no defects remain.

## Verification rerun

Re-ran the parsed manifest commands and the milestone ledger commands without destructive database operations:

| Command | Exit | Evidence |
|---|---:|---|
| `./vendor/bin/pest tests/Unit/ExpandedChecks tests/Feature/ExpandedChecks --compact` | 0 | 18 passed / 138 assertions. |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | 0 | No errors. |
| `./vendor/bin/pest tests/Feature/ExpandedChecks/ExpandedChecksQueueRuntimeTest.php --compact` | 0 | 2 passed / 52 assertions, including real database queue workers. |
| `./vendor/bin/pint --test tests/Feature/ExpandedChecks` | 0 | Pint passed. |
| `find tests/Feature/ExpandedChecks -name '*.php' -print0 \| xargs -0 -n1 php -l && php -l tests/Feature/ExpandedChecks/Support/expanded-artisan` | 0 | All expanded-check feature/runtime PHP files parsed successfully. |
| `./vendor/bin/pest --compact` | 0 | Full regression passed: 304 tests / 1602 assertions. |

Database safety: I did not run Artisan migration commands or destructive database commands. The real-worker tests create unique SQLite files under `build/expanded-check-runtime-tests/`, configure child processes with `DB_CONNECTION=sqlite`, and the test-only `expanded-artisan` refuses queue databases outside the workspace.

## Acceptance criteria

| ID | Result | Evidence |
|---|---|---|
| AC-agent-v2-expanded-monitors-10 | Pass | Domain tests prove Unicode/mixed-case canonicalization and RDAP authoritative persistence (`tests/Feature/ExpandedChecks/ExpandedWebsiteEvaluatorsTest.php`), and invalid timeout/malformed/no-expiry refreshes retain the prior observation while emitting bounded failure reason codes. The new runtime test drives `checkybot:expanded-refresh-domains`, `queue:work`, `checkybot:alerting-retries`, `RequestPullRecheck`, and chained alerting processing through real database queue workers; the file-backed lookup records fresh calls at 12:00:00, +10s, and +30s, retains the prior WHOIS observation across the two failures, and persists the final RDAP success. |
| AC-agent-v2-expanded-monitors-11 | Pass | Clock-controlled feature coverage verifies >30 whole days is healthy, exactly 30 days through expiry is warn, post-expiry is critical, absent/stale lookup data creates unavailable evaluations without healthy ingestion, and submitted results use deterministic operation IDs under the monitor project identity (`EvaluateDomainExpiry` UUIDv5 per monitor/minute). |
| AC-agent-v2-expanded-monitors-12 | Pass | Response-budget tests verify target/project/status/age filtering, finite successful speed samples only, nearest-rank p95, exactly 2000 ms healthy, 2001 ms warn, an extreme tail value affects p95, and empty/non-finite/failed/stale/cross-project samples remain unavailable rather than healthy. |
| AC-agent-v2-expanded-monitors-13 | Pass | Schedule inspection verifies daily domain refresh and one-minute domain/p95 evaluation use `withoutOverlapping` and `onOneServer`. The real-worker runtime invokes all three scheduler target commands twice, processes duplicate p95 jobs in two independent barrier-synchronized `queue:work` processes, drains duplicate domain refresh/domain-budget and alerting jobs through real workers, and verifies exactly three submitted evaluations/processed alerting results, no disabled-check evaluations, no pre-worker direct state/transition mutation, and an empty jobs table. |

## Findings

No changes requested.

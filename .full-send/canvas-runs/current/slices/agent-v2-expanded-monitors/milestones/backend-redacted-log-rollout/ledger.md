# Backend redacted log rollout — verification ledger

## Scope

Implemented spec section `backend-redacted-log-rollout` only, covering AC-agent-v2-expanded-monitors-14 through AC-agent-v2-expanded-monitors-16.

## Implementation

- Added an agent-owned `RedactedLogSnippetProvider` contract, immutable authorized request/result DTOs, Eloquent read implementation, and container binding.
- The provider requires matching foundation project authorization, a server monitor identity, an enabled same-project registration, explicit `share_redacted_logs=true`, valid source allow-list and limit, and a valid incident window.
- Reads are bounded with `limit + 1`, ordered by `observed_at` and persisted ID, scoped through the report/server project, and recursively redacted again with the foundation `RecursiveRedactor`.
- Every response hard-codes `alert_eligible=false`; the implementation has no alerting/action dependencies and performs only server/line reads.
- Added `docs/agent-v2-rollout.md` with executable preflight, canary, inventory, token rotation, rollback, cap, state-storage, and prerequisite-remediation commands.

## Acceptance evidence

| Acceptance criterion | Evidence |
|---|---|
| AC-14 | `RedactedLogSnippetProviderTest.php` proves default-disabled, authorization-project mismatch, cross-project server identity, mixed unsupported-source, and non-matching-window requests return no lines. The opted-in case persists deliberately unsafe context and proves a 4-line bound, truncation, stable timestamp/ID ordering, source order, and second-pass removal of query values, Authorization credentials, Cookie values, configured literals, email addresses, and IPv4 addresses. |
| AC-15 | The side-effect test snapshots `monitor_states`, `monitor_transitions`, `alerting_incident_groups`, `outbox_events`, and `alerting_notification_intents` before denied and successful provider calls, then proves every count is unchanged and both results have `alertEligible=false`. |
| AC-16 | The documentation contract test verifies the rollout guide contains executable bash stages for nginx access-log enablement, `adm` or bounded sudo reads, FPM and optional MySQL sources, 0600 state, 1 Gbit/s default and positive cap override, scoped token issue/rotation/revocation, canary waves, fleet `agent_version` inventory, rollback, and explicit remediation of `readable`, `missing`, `permission_denied`, `disabled`, and `not_configured`. |

## Verification runs

| Command | Exit | Result |
|---|---:|---|
| `./vendor/bin/pest tests/Feature/AgentV2/RedactedLogSnippetProviderTest.php --compact` | 0 | 4 passed / 51 assertions. |
| `./vendor/bin/pest tests/Unit/AgentV2 tests/Feature/AgentV2 --compact` | 0 | 20 passed / 289 assertions. |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | 0 | No errors. |
| `./vendor/bin/pint --test src/Domain/Agent tests/Feature/AgentV2/RedactedLogSnippetProviderTest.php` | 0 | Formatting passed after applying Pint once to the new result DTO. |
| PHP syntax checks for all new provider/request/result/test files | 0 | No syntax errors. |
| `./vendor/bin/pest --compact` | 0 | Full regression: 308 passed / 1653 assertions. |
| `git diff --check` | 0 | No whitespace errors. |

## Database safety

No Artisan migration command, destructive database command, or host service command was run. Pest/Testbench configured an in-memory SQLite connection and invoked only package migration `up()` methods inside the isolated test process.

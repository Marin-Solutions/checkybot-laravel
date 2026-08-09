# Agent v2 and expanded monitor evaluators — slice specification

## Purpose

Deliver the PRD phase-3 monitoring breadth without bypassing the already-approved domain and alerting runtimes. This slice owns the stateful agent collector, authenticated v2 report ingestion, server metric/log/prerequisite/dead-man evaluation, domain-expiry and response-budget evaluation, the redacted log-snippet provider, and fleet rollout guidance. Every evaluator submits normalized observations through `MonitorResultIngestionInterface`; it never writes monitor lifecycle state or creates alerts directly.

This is a backend-only slice. It owns no product frontend files, screens, or design references, so no frontend milestone or visual acceptance criterion is applicable. Prerequisite/status data is exposed as backend domain state for later owning surfaces; this slice does not create a card.

## Base requirements

- PRD §4.1: agent v2 is explicitly versioned and persists network rx/tx counter state across one-minute runs.
- PRD §4.1: operators provision log access through `adm` membership or least-privilege sudo, and unavailable configured logs become `misconfigured`, never a silent healthy zero.
- PRD §4.1: network uses 70/90% of a configurable per-server cap, default 1 Gbit/s.
- PRD §4.1: FPM warns at 80% active workers and treats any five-minute `max_children` reach as a critical candidate.
- PRD §4.1: nginx uses five-minute request windows, the >1%/>5% rules at 50+ requests, the absolute 10-failure critical fallback below 50 requests, and static upstream-timeout matching.
- PRD §§4.1 and 11: the server-side dead-man starts warning evaluation after three missed one-minute reports and critical evaluation after five.
- PRD §4.2: domain expiry warns with 30 days remaining, and p95 stored response speed warns above two seconds.
- PRD §4.1 v1.5 boundary: log snippets are optional, filtered, redacted context for annotation only; static evaluators remain the sole result producers.
- Inherited `MonitorDomainContracts`: use foundation monitor identity, canonical lifecycle/severity, redaction, and outbox conventions. Agent-owned `ready|misconfigured` is prerequisite/sample status, not a fifth lifecycle state.
- Inherited `MonitorResultIngestionInterface`: one aggregate server result per observation prevents a healthy sibling metric from overwriting a breach. Domain-expiry and response-budget checks use their own website identities.
- Canvas integration requirement: prove the owned evaluator-to-alerting seam through real authenticated HTTP, registered commands/jobs, the real queue worker and relay, and real receipt HTTP in the canonical Playwright runtime.

## Inherited ownership

Implementation is limited to the inherited ownership set:

- `agent`
- `app/Domain/Agent`
- `app/Domain/ExpandedChecks`
- `app/Http/Controllers/AgentReportController.php`
- `app/Jobs/Agent`
- `app/Jobs/ExpandedChecks`
- `database/migrations/2026_08_06_030000-2026_08_06_039999`
- `docs/agent-v2-rollout.md`
- `routes/agent.php`
- `tests/Feature/AgentV2`
- `tests/Feature/ExpandedChecks`
- `tests/Unit/AgentV2`
- Migration timestamp range: `2026_08_06_030000-2026_08_06_039999`

Milestone ledger, manifest, review, and handoff artifacts additionally live in this slice's own `milestones/` directory.

## Scope boundaries and invariants

- The agent report is immutable by operation UUID. Persistence commits before evaluator dispatch; identical retries deduplicate and conflicting reuse fails.
- Project identity comes only from the bearer token. A server UUID must belong to that token's project.
- Agent state is locked, mode `0600`, and atomically replaced. First observation, counter reset, invalid elapsed time, and interface hot-plug produce unavailable baseline/reset samples, not zero throughput.
- Raw metrics and prerequisite details remain in this slice. `reason_code` contains only a bounded dominant static-rule code.
- A report creates one worst-band server observation. CPU, memory, disk, network, FPM, nginx, and prerequisites must not race independent healthy results against one server identity.
- Static rules produce candidates; alerting owns three-sample hysteresis, recovery behavior, lifecycle persistence, grouping, and notification intent creation.
- Configured access-log absence persists `misconfigured` and yields a warning candidate. Rate evaluation is skipped because there is no valid denominator.
- The dead-man uses the last accepted report's observed time, not request arrival time, and rejects out-of-order attempts to revive liveness.
- A failed domain refresh never fabricates an expiry or overwrites the last authoritative observation. Alerting pull retries execute a real lookup.
- P95 is nearest-rank over the source read model's bounded retained, successful, finite samples for one check. No valid samples cannot mean healthy.
- Redacted snippets are disabled by default, project-authorized, time/source/limit bounded, and recursively redacted again when read. The provider has no alerting side effects.
- Harness-only receipt routes remain loopback-bound and absent in production.
- Runtime verification may use only the canonical harness's run-scoped SQLite database and child worker processes; it must not alter shared DB/Redis/host services.

## API and interface contracts

### `POST /api/v2/agent-reports`

Authentication uses an active foundation `ProjectApiToken` with `agent:report`. The token chooses the project; the body cannot override it.

Required body:

- `schema_version`: literal `agent-report.v2`.
- `operation_id`: immutable UUID.
- `agent_version`: semantic version.
- `server_uuid`: enabled registered server in the token project.
- `observed_at`: RFC3339 UTC.
- `reporting_interval_seconds`: v2 default/supported cadence `60`.
- CPU five-minute percentage and memory used percentage.
- Disk mount, used percentage, and nullable predicted days to full.
- Per-interface rx/tx totals, nullable deltas/elapsed time, and `baseline|ready|reset` status.
- FPM pool active workers, max children, and five-minute reached count.
- A 300-second nginx request/5xx/upstream-timeout window.
- Explicit prerequisite records for nginx access/error, FPM status/log, and optional MySQL log.
- Optional bounded, locally redacted relevant nginx/FPM/MySQL lines.

Validation rejects unknown fields/version, invalid timestamp skew, duplicate interfaces, negatives, invalid percentages, inconsistent deltas, wrong nginx window, unbounded lines, and detectable unredacted secrets. A valid request returns `202` with report UUID, server UUID, and deterministic evaluation operation UUIDs. Identical replay returns `200 duplicate`; conflicting reuse returns `409`; auth/project failures return `401|403`; validation returns `422`.

### `MonitorResultIngestionInterface`

Evaluator jobs resolve the inherited interface and submit foundation identity, deterministic operation UUID, UTC observation time, bounded reason code, and either:

- `push` plus `healthy|warn|critical`, finite aggregate band value, and ordered thresholds; or
- `pull` plus `success|failure` for a real external lookup transport result.

The response is `queued|duplicate` with operation UUID and optional next retry time. No evaluator directly mutates `MonitorState` or `MonitorTransition`.

### `DomainExpiryLookup::lookup(DomainName)`

The input is canonical ASCII/IDNA host only—no scheme, path, credentials, or port. An injectable WHOIS/RDAP adapter has a bounded timeout, redacted diagnostics, and deterministic fake. Success returns canonical domain, authoritative expiry, `whois|rdap` source, and fetch time. Failure is typed retryable/terminal and never fabricates an expiry. Success is cached until daily refresh.

### `RedactedLogSnippetProvider::forIncident(AuthorizedLogSnippetRequest)`

The request carries an authorized foundation project/server identity, UTC incident window, a non-empty `nginx|fpm|mysql` source subset, and limit 1–200. The server's `share_redacted_logs` setting must be explicitly enabled; default is disabled.

The response returns ordered `{source, observed_at, redacted_line}` rows, truncation flag, redaction version, and `alert_eligible: false`. Disabled, unauthorized, cross-project, unsupported, and out-of-window requests return no lines.

### Harness receipt read

`GET /__harness/alerting/receipts/{operation_id}` is the inherited loopback-only testing route used to observe normalized evaluator processing, canonical state/transitions, retries, and receipts. It is absent in production.

## Server evaluation semantics

A queued report evaluates:

- CPU five-minute average: warn `>=85`, critical `>=95`.
- RAM used: warn `>=85`, critical `>=95`.
- Disk used: warn `>=80`, critical `>=90`, plus the existing predicted-full-within-seven-days critical rule.
- Network: `max(rx, tx) * 8 / elapsed_seconds`, warn `>=70%`, critical `>=90%` of current positive server cap.
- FPM: active/max warn `>=80%`, critical at saturation; any `max_children_reached_5m > 0` is critical candidate.
- Nginx at 50+ requests: healthy through exactly 1%, warn above 1%, critical above 5%.
- Nginx below 50 requests: critical at 10 or more 5xx; percentage warn is not applied.
- Any matched upstream timeout is a critical candidate and participates in correlated 5xx context.
- A configured missing/disabled/permission-denied access log is `misconfigured` and a warning candidate; no 0% rate is computed.

The deterministic dominant reason comes from the worst rule while complete raw details remain persisted.

The dead-man scanner is overlap-safe and operation-idempotent. Before 180 seconds it produces no warning candidate; at 180–299 seconds it produces warn candidates; at 300 seconds and later it produces critical candidates. Those candidates go through inherited confirmation/hysteresis. A fresh accepted report resets local elapsed liveness and subsequent healthy aggregate samples drive alerting recovery.

## Expanded website checks

Domain lookup refreshes daily. A failed transport submits a pull failure so +10/+30 retries perform the real lookup. A minute evaluator uses only non-stale authoritative expiry: more than 30 whole days is healthy, 0–30 days is warn, and expired is critical. Minute candidates allow inherited confirmation without repeatedly querying WHOIS/RDAP.

The response-budget evaluator runs every minute over the bounded retained speed read model and computes nearest-rank p95. Exactly 2000 ms is healthy; values above 2000 ms are warn-only. Empty, stale, failed, non-finite, or cross-project samples do not produce healthy.

## Backend milestone: Stateful agent v2 collection and report ingestion

<a id="backend-agent-v2-report-runtime"></a>

### Scope

Build the v2 collector/state file, report DTO and auth boundary, report/sample/prerequisite/settings persistence, static parser/redaction boundary, and queued report evaluation dispatch.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-agent-v2-report-runtime/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-agent-v2-report-runtime/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-agent-v2-report-runtime/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-agent-v2-report-runtime/handoff.md`

### Acceptance criteria

- **AC-agent-v2-expanded-monitors-1:** Fixture-driven collector tests prove versioning, correct second-run deltas, safe baseline/hot-plug/reset behavior, and locked atomic mode-0600 state persistence.
- **AC-agent-v2-expanded-monitors-2:** Agent API feature tests prove all auth, validation, atomic queueing, identical replay, and conflicting-operation response contracts.
- **AC-agent-v2-expanded-monitors-3:** Parser/persistence tests prove FPM, nginx, prerequisite, and redacted-line fields survive correctly with no declared secret/PII corpus value persisted.
- **AC-agent-v2-expanded-monitors-4:** Settings tests prove the 1 Gbit/s/default-disabled settings, valid project-isolated override behavior, and rejection of invalid/cross-project overrides.

## Backend milestone: Server metric, log, prerequisite, and dead-man evaluators

<a id="backend-agent-monitor-evaluators"></a>

### Scope

Register the report evaluator job, due-evaluator command, queue binding, dead-man schedule, aggregate server rules, and the owned `MonitorResultIngestionInterface` seam.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-agent-monitor-evaluators/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-agent-monitor-evaluators/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-agent-monitor-evaluators/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-agent-monitor-evaluators/handoff.md`

### Acceptance criteria

- **AC-agent-v2-expanded-monitors-5:** Table-driven tests prove CPU/RAM/disk/network boundaries, predictive disk behavior, baseline exclusion, cap use, and one worst-band server result per report.
- **AC-agent-v2-expanded-monitors-6:** FPM tests prove utilization/max-children rules and misconfiguration behavior without invented zero-worker health.
- **AC-agent-v2-expanded-monitors-7:** Nginx tests prove all percentage, minimum-volume, absolute-fallback, timeout, and unavailable-access-log rules at their exact boundaries.
- **AC-agent-v2-expanded-monitors-8:** Clock tests prove dead-man candidate timing, idempotent scans, fresh-report reset, and out-of-order rejection.
- **AC-agent-v2-expanded-monitors-9:** The canonical full-runtime Playwright journey posts three real authenticated high-network reports, executes registered evaluator/relay infrastructure with the real worker, and observes confirmed alerting state through the real receipt API without direct service invocation.

## Backend milestone: Domain-expiry and p95 response-budget evaluators

<a id="backend-expanded-website-evaluators"></a>

### Scope

Build domain normalization/lookup/cache/retries, stored-speed p95 reads, minute evaluators, deterministic ingestion, and overlap-safe registration.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-expanded-website-evaluators/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-expanded-website-evaluators/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-expanded-website-evaluators/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-expanded-website-evaluators/handoff.md`

### Acceptance criteria

- **AC-agent-v2-expanded-monitors-10:** Lookup tests prove canonical domain handling, authoritative success persistence, prior-value preservation, redacted failures, and real +10/+30 lookup retries.
- **AC-agent-v2-expanded-monitors-11:** Clock tests prove exact >30/0–30/expired bands, stale/absent handling, deterministic operation IDs, and project identity.
- **AC-agent-v2-expanded-monitors-12:** P95 tests prove nearest-rank target-check isolation, 2000/2001-ms boundary behavior, tail behavior, and exclusion of invalid/stale/cross-project samples.
- **AC-agent-v2-expanded-monitors-13:** Runtime tests prove daily/minute schedules, overlap/idempotency, disabled behavior, and exclusive use of the ingestion interface for lifecycle effects.

## Backend milestone: Redacted log snippet boundary and fleet rollout guide

<a id="backend-redacted-log-rollout"></a>

### Scope

Implement the bounded provider with defense-in-depth redaction and no alert side effects, then document prerequisite setup, canary/fleet rollout, verification, and rollback.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-redacted-log-rollout/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-redacted-log-rollout/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-redacted-log-rollout/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/milestones/backend-redacted-log-rollout/handoff.md`

### Acceptance criteria

- **AC-agent-v2-expanded-monitors-14:** Provider contract tests prove default denial, authorization/project/source/window/limit enforcement, deterministic order, and complete second-pass corpus redaction.
- **AC-agent-v2-expanded-monitors-15:** Side-effect tests prove provider calls never change lifecycle, incident, outbox, or notification data and always return `alert_eligible=false`.
- **AC-agent-v2-expanded-monitors-16:** A documentation contract test proves the rollout guide contains every declared permission, storage, cap, token, canary, inventory, rollback, and prerequisite-remediation step.

## Review expectations

Review must confirm human and machine contracts agree; every implementation and milestone artifact stays within inherited ownership; package-path equivalents are used consistently; no product frontend is added; agent state cannot create a false zero; token/project isolation and immutable idempotency hold; one aggregate server result prevents sibling races; missing logs remain visible; dead-man and threshold boundaries pass clock/table tests; domain and p95 jobs never fabricate healthy data; snippets remain opt-in/redacted/annotation-only; all commands/jobs/schedules are registered; and the owned seam crosses real HTTP, worker/relay, and receipt HTTP in the canonical full-runtime harness.

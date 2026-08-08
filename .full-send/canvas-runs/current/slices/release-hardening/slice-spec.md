# Release hardening, proving period, and handover gates — slice specification

## Purpose

Turn the Checkybot v1 success criteria into fail-closed handover gates. This slice does not add product behavior. It supplies operator runbooks, machine-checkable checklist/evidence templates, release-level feature and component verification, and one final production-shaped Playwright proof with a real queue worker.

A release is not ready merely because individual delivery slices passed. Handover evidence must jointly prove no notification for a deploy blip shorter than 30 seconds, bounded incident/recovery notification cardinality, stale-green prevention, an independent external watchdog, 28 consecutive complete dual-send days before retiring the Telegram fallback, and an accountable agent-v2 fleet rollout.

## Base requirements

- Canvas polish mandate: define and prove the v1 release and handover gates for false-alarm prevention, widget freshness, external watchdog operation, push fallback retirement, fleet rollout, operator documentation, and canonical evidence.
- PRD §4.4 and §9 criterion 5: the Telegram fallback cannot retire until an external watchdog independent of the Checkybot host is live.
- PRD §5.1–§5.3 and §9 criteria 1–2: downtime shorter than 30 seconds produces zero notifications; a real incident produces at most one grouped incident notification plus one grouped recovery.
- PRD §5.5 and §9 criterion 5: every critical incident remains dual-sent to Expo and the existing generic legacy webhook until 28 consecutive complete UTC days prove both channels without a missing or failed pair.
- PRD §6.1–§6.2: status surfaces expose loading, explicit healthy/empty, error/offline, and last-synced states; data older than 15 minutes must never look healthy green.
- PRD §4.1 and rollout phase 3: release evidence accounts for the agent-v2 fleet, prerequisites, one-minute reporting, canary waves, tokens, rollback, and version inventory.
- Canvas integration requirement: final evidence comes from real HTTP against run-scoped SQLite, registered jobs/relay, a real queue worker, and a built production-shaped surface.

## Inherited ownership

Implementation is limited to this exact set:

- `docs/release-hardening.md`
- `docs/watchdog-and-push-retirement.md`
- `.full-send/canvas-runs/current/handover-checklists`
- `tests/Feature/ReleaseHardening`
- `tests/Component/ReleaseHardening`
- Migration timestamp range: `none`

Each milestone's ledger, manifest, review, and handoff files live beneath this slice's own `milestones/` directory.

## Scope boundaries and safety

- Do not modify product routes, controllers, domain logic, jobs, migrations, runtime scripts, mobile/widget/web components, or upstream slice evidence.
- Release tests consume the contracts already delivered by the foundation, alerting, push/mobile/widget, agent-v2, dashboard, and canonical harness slices.
- No new API or integration seam is introduced. Harness routes remain loopback-only and absent in production.
- Runtime verification must use a unique run-scoped SQLite database and child processes started and recorded by the canonical harness. It must reject a non-SQLite or non-run-scoped database before launch.
- Cleanup may stop only recorded child processes. No shared database, Redis, Supervisor, or host service may be controlled.
- Evidence must be redacted. Plaintext bearer tokens, Expo credentials/tokens, watchdog/webhook URLs, provider bodies, and unredacted monitor payloads fail the handover gate.
- There are no owned screens or reference captures, so prototype visual-parity criteria do not apply.

## Handover evidence model

The checklist templates under `.full-send/canvas-runs/current/handover-checklists` are the system of record for release sign-off. They reference immutable or reproducible source evidence rather than copying secrets or large raw logs. Every gate records an owner, observation timestamp, validity/expiry rule, result, and evidence path.

Required gates are conjunctive:

1. **Alert trust:** a sub-30-second failure/recovery creates no intent or delivery; a confirmed grouped incident and recovery create exactly one logical notification per phase.
2. **Freshness:** empty/healthy, error/offline, and stale states are surfaced explicitly; no stale or failed summary can be presented with healthy-green semantics.
3. **Watchdog:** an external service independent of the Checkybot host has a current successful heartbeat and an independently delivered test alert/escalation proof.
4. **Push proving:** the inherited reliability read model reports 28 consecutive complete UTC days and no failed/missing critical Expo plus generic-webhook pair.
5. **Fleet:** every enabled server is represented by current `agent-report.v2` inventory and a staged rollout/rollback record.
6. **Canonical runtime:** a built status surface, real HTTP, the registered async path, and a real queue worker pass in one recorded run with accounted cleanup.

Failure or stale evidence for any required gate leaves handover unsigned. Manual exceptions are allowed only where the PRD permits them (for example optional MySQL context); they must be explicit, owned, dated, and must not weaken required nginx/FPM or alerting behavior.

## Consumed API and interface contracts

### `GET /api/status-summary`

Frontend consumer: production mobile/widget/status surface.

- Request: JSON accept header and bearer `ProjectApiToken` with `status:read`; project identity comes only from the token.
- `200`: nine non-negative integer counts (`servers|websites|apis` × `healthy|warn|down`), `updated_at` as RFC3339 UTC or null, and server-computed `stale`.
- `401`: missing/invalid token. `403`: token lacks `status:read`.
- Stale is true when `updated_at` is null or older than 900 seconds. The client additionally enforces local age so clock progression after a successful response cannot remain green.

### `POST /__harness/alerting/results`

Backend test consumer; available only in the canonical loopback testing runtime.

- Request: immutable UUID `operation_id`, typed project/monitor identity, `pull|push` source, RFC3339 `observed_at`, compatible signal, bounded redacted reason, and push-only finite value/ordered thresholds.
- `202`: `{operation_id, status: "queued"}`. Identical replay returns `200 duplicate`. Invalid contract returns `422`.
- Acceptance queues the registered alerting path; release tests must not call a processor directly.

### `GET /__harness/alerting/receipts/{operation_id}`

Backend test consumer; canonical loopback runtime only.

- `200`: operation status, current state, transitions, retries, groups, and `notification-intent.v1` records.
- `404`: unknown operation.

### `GET /__harness/push/receipts/{operation_id}`

Backend test consumer; canonical loopback runtime only.

- `200`: listener state, redacted Expo delivery fields, `accepted|failed|not_required` generic-webhook result, reliability-recorded flag, and processing timestamp.
- `404`: unknown push operation.

### `PushReliabilityReadModel::forProject(ProjectIdentity)`

Backend release-gate consumer.

The fixed trailing window returns proving start/end, critical intent count, Expo and legacy-webhook accepted counts, failed/missing pair count, complete consecutive days, last failure, and `retirement_ready`. Readiness is true only after 28 consecutive complete UTC days. Duplicate receipts cannot inflate it, and any missing/failed required pair prevents it.

### `GET {CHECKYBOT_WATCHDOG_URL}` outbound

Backend release-gate consumer.

The overlap-safe minutely scheduler performs a bounded-timeout GET to a secret HTTPS external heartbeat URL. Any 2xx is a successful heartbeat. Missing configuration, timeout, transport error, and non-2xx cannot satisfy handover. URL credentials/query values stay redacted, and failure cannot prevent the next attempt.

### `POST /api/v2/agent-reports`

Backend fleet-proof consumer.

- Authorization: active project token with `agent:report`; the server must belong to that project.
- Request: literal `agent-report.v2`, immutable operation UUID, semantic agent version, registered server UUID, RFC3339 observation, 60-second interval, metric/log/prerequisite samples, and only bounded already-redacted context.
- `202 queued`; identical replay is `200 duplicate`; missing auth `401`; scope violation `403`; operation collision `409`; invalid schema, interval, values, timestamp, or unsafe log data `422`.

## Backend milestone: Release gates, proving-period policy, and operator runbooks

<a id="backend-release-gates-runbooks"></a>

### Scope

Within inherited ownership, create `docs/release-hardening.md`, `docs/watchdog-and-push-retirement.md`, and versioned checklist/evidence templates. Add Pest feature and documentation-contract tests under `tests/Feature/ReleaseHardening`.

The release gate composes existing facts rather than introducing another product state machine. It must remain blocked unless all evidence is current and passing. Telegram is documented accurately as the existing generic legacy webhook, not a new first-class channel. Watchdog proof must include independent escalation delivery, not only local scheduler configuration. Fleet readiness references `docs/agent-v2-rollout.md` as an upstream runbook but records release-specific inventory, wave results, owner, and rollback evidence in this slice's checklist.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/release-hardening/milestones/backend-release-gates-runbooks/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/release-hardening/milestones/backend-release-gates-runbooks/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/release-hardening/milestones/backend-release-gates-runbooks/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/release-hardening/milestones/backend-release-gates-runbooks/handoff.md`

### Acceptance criteria

- **AC-release-hardening-1:** A Pest release-gate feature test drives a failed pull observation and successful recheck less than 30 seconds later through real `POST /__harness/alerting/results` requests with the real queue worker and registered relay running, then proves through real alerting and push receipt APIs that zero incident/recovery intents and zero Expo or legacy-webhook deliveries were created.
- **AC-release-hardening-2:** A Pest release-gate feature test drives a confirmed multi-monitor critical incident and full recovery through the real harness APIs and queue worker, then proves exactly one grouped incident intent and one grouped recovery intent share one thread key and that each required critical intent has one deduplicated Expo acceptance and one deduplicated legacy-webhook acceptance.
- **AC-release-hardening-3:** Clock-controlled proving-gate tests prove the Telegram/generic-webhook retirement checklist remains blocked when the external watchdog proof is missing, stale, failed, or dependent on Checkybot, when fewer than 28 consecutive complete UTC days exist, or when any critical Expo/webhook receipt is failed or missing, and becomes signable only when an independent current watchdog proof and `retirement_ready=true` are both present.
- **AC-release-hardening-4:** Fleet-readiness contract tests prove the handover manifest fails unless every enabled server is inventoried with an accepted `agent-report.v2` at a 60-second interval, expected semantic version, readable required nginx/FPM prerequisites, an explicitly approved optional-MySQL exception when applicable, cap confirmation, canary-wave result, scoped-token rotation evidence, rollback owner, and post-rollback heartbeat procedure.
- **AC-release-hardening-5:** Documentation contract tests prove `docs/release-hardening.md`, `docs/watchdog-and-push-retirement.md`, and the handover checklist templates define executable commands, pass/fail thresholds, evidence paths, responsible owner and timestamp fields, recency/expiry rules, rollback triggers, the generic-webhook Telegram mapping, fleet rollout ordering, and a check that rejects plaintext tokens, provider credentials, webhook URLs, or unredacted payloads from the evidence bundle.

## Frontend milestone: Final status states and canonical handover journey

<a id="frontend-final-states-runtime-proof"></a>

### Scope

Within inherited ownership, add release-level component and Playwright checks under `tests/Component/ReleaseHardening`, and write completed evidence beneath `.full-send/canvas-runs/current/handover-checklists`. Tests import and exercise existing production components; this slice does not edit them.

Component checks cover loading, explicit all-healthy/empty, problems, offline/error with cached last-sync age, and widget stale/error behavior. The stale contract is fail-closed for `updated_at=null`, server `stale=true`, local age greater than 900 seconds, authorization failures, and transport failure.

The final Playwright run builds and serves an existing production-shaped status surface. It starts only canonical harness-owned processes, verifies run-scoped SQLite, keeps a real queue worker active, uses real harness/status HTTP, and retains complete evidence on pass or failure. It creates both a harmless deploy blip and a confirmed incident; it must observe the UI and receipt APIs rather than directly invoking jobs or processors.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/release-hardening/milestones/frontend-final-states-runtime-proof/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/release-hardening/milestones/frontend-final-states-runtime-proof/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/release-hardening/milestones/frontend-final-states-runtime-proof/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/release-hardening/milestones/frontend-final-states-runtime-proof/handoff.md`

### Acceptance criteria

- **AC-release-hardening-6:** Release-level component tests import the production status components and prove first load shows loading, zero warn/down counts show an explicit all-healthy state rather than a blank surface, nonzero warn/down counts show only the expected problems, and a refresh failure shows a persistent error/offline state with the cached summary and last synced age.
- **AC-release-hardening-7:** Clock-controlled widget component checks prove updated age is always visible, data is non-stale through exactly 900 seconds, and `updated_at=null`, `stale=true`, local age greater than 900 seconds, `401/403`, or transport failure renders the distinct warning/dimmed stale or error treatment with no healthy-green label, accessibility state, or healthy color token.
- **AC-release-hardening-8:** One canonical full-runtime Playwright journey against a built and served production-shaped status surface proves, with the real queue worker running, that a sub-30-second deploy blip creates no push receipt, a confirmed critical group creates deduplicated Expo and legacy-webhook receipts, authenticated `GET /api/status-summary` updates the surfaced counts, and a forced API failure retains last-synced data in a visibly stale/error state.
- **AC-release-hardening-9:** The canonical journey exits 0 only after writing a handover evidence manifest containing run ID, commit, start/end timestamps, exact commands and endpoint URLs, run-scoped SQLite path, queue-worker start and processed-job proof, alerting/push/status-summary response snapshots, component and Playwright summaries, screenshot/trace/log paths, watchdog and 28-day proving references, fleet inventory reference, secret-scan result, and cleanup proof that no recorded child process remains alive.

## Review expectations

Review must confirm:

- the human and machine specs agree, all nine criteria are binary, and milestone artifact paths are unique and local to this slice;
- all implementation stays within inherited ownership and no migration or product behavior was added;
- retirement requires both independent watchdog proof and the complete 28-day paired-delivery result;
- final runtime evidence uses real HTTP, registered async infrastructure, a real queue worker, and a built status surface;
- stale/error states cannot be confused with healthy green; and
- the evidence bundle is reproducible, redacted, timestamped, and complete enough for an operator to accept or reject handover without relying on oral context.

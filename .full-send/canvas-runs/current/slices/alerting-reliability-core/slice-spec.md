# Trustworthy alerting state machine, grouping, maintenance, and watchdog — slice specification

## Purpose

Build the trust-first alerting core between normalized monitor results and downstream delivery. This slice owns result ingestion, retry/hysteresis policy, persisted transitions, incident grouping, notification-intent creation, maintenance suppression and expiry catch-up, the incident timeline read model, and the external watchdog ping. Push/Telegram delivery, monitor evaluators, web/mobile UI, and AI annotation execution remain in their owning slices.

This is a backend-only slice. It exposes a maintenance-status API for later UI consumers and backend interfaces/events for other slices, but no product screen or frontend file is owned, so there is no frontend milestone.

## Base requirements

- PRD §§5.1–5.2: all website, API, and server pipelines use one persisted lifecycle and notify only after confirmation, never from raw results.
- PRD §5.2: pull checks perform actual rechecks after 10 and 30 seconds; pushed metrics use three-sample confirmation and a five-point recovery margin.
- PRD §5.3: confirmed incidents collect for 30 seconds, coalesce into an open group for five minutes, and create at most one incident intent plus one recovery intent.
- PRD §5.4: global and project maintenance extend `HasSnooze`, `isSilencedNow`, and `ProcessExpiredSnoozes`; machine access uses scoped rotatable project tokens, and expiry catches up current problems once.
- PRD §4.4: the scheduler sends an external heartbeat every minute so failure of Checkybot itself is detected independently.
- PRD §9: downtime shorter than 30 seconds creates no notification, and a real incident creates at most one incident notification plus one recovery notification.
- Inherited `MonitorDomainContracts`: monitor types are `server|website|api`; lifecycle states are `healthy|warn|down|recovering`; severities are `warn|critical`; project tokens, transitions, redaction, and outbox behavior come from the foundation slice.
- Canvas integration requirement: the real HTTP boundary, registered relay, queue worker, scheduler/jobs, and receipt API are used in end-to-end evidence.

The PRD term **degraded** maps to the foundation's canonical `warn` lifecycle state. This slice must not introduce a fifth state or redefine shared enums.

## Inherited ownership

Implementation is limited to this exact ownership set:

- `app/Domain/Alerting`
- `app/Domain/Maintenance`
- `app/Http/Controllers/MaintenanceModeController.php`
- `app/Jobs/Alerting`
- `app/Notifications/Alerting`
- `app/Policies/MaintenanceModePolicy.php`
- `database/migrations/2026_08_06_010000-2026_08_06_019999`
- `routes/maintenance.php`
- `tests/Feature/Alerting`
- `tests/Feature/MaintenanceMode`
- `tests/Unit/Alerting`
- Migration timestamp range: `2026_08_06_010000-2026_08_06_019999`

Milestone ledger, manifest, review, and handoff artifacts are additionally written under this slice's own `milestones/` directory.

## Scope boundaries and invariants

- `MonitorState` and `MonitorTransition` from the foundation remain authoritative for current state and history. Alerting-owned tables store evaluation counters, retry requests, incident groups/memberships, maintenance records, and notification intents.
- An accepted operation UUID is immutable and idempotent. State mutation and related outbox writes commit atomically.
- Results older than the last processed observation cannot regress state. Concurrent workers cannot duplicate transitions, groups, or intents.
- A pull retry means rerunning the producer's real probe. Reprocessing the same failed payload after a delay is not a retry and must never advance the failure count.
- Raw results, `warn`, and `recovering` do not notify. Only confirmed `down` and the final confirmed recovery may create intents.
- Incident grouping is project-scoped. Global maintenance catch-up creates separate groups per project so recipients and data never cross project boundaries.
- Maintenance suppresses notification intent creation, not monitoring or history. Expiry evaluates current persisted state rather than replaying every suppressed transition.
- Notification intent production belongs here; Expo, Telegram/webhook, sounds, Time Sensitive classification, retries to channel providers, and delivery receipts belong to the push slice.
- The external watchdog must not alert through Checkybot itself. The configured external heartbeat service owns missed-ping escalation.
- Harness routes are loopback-bound and testing-only and must be absent in production.
- Verification may use only the canonical harness's run-scoped SQLite database and child worker processes. It must not alter shared databases, Redis, Supervisor, or host services.

## Domain and cross-slice contracts

### `MonitorResultIngestionInterface`

`ingest(NormalizedMonitorResult)` is queue-safe and accepts:

- An idempotent operation UUID.
- Foundation `MonitorIdentity`: project UUID, monitor UUID, and `server|website|api`.
- `source`: `pull|push`.
- RFC3339 UTC `observed_at`.
- Pull signal `success|failure`, or push signal `healthy|warn|critical`.
- A bounded reason code and already-redacted metadata.
- Push-only finite value and ordered warn/critical thresholds, with a non-negative recovery delta defaulting to five percentage points.

The response reports `queued|duplicate` and the next retry due time when applicable. Invalid source-specific fields fail before enqueueing. Producer slices resolve this interface from the alerting module provider rather than importing a concrete transition service.

The alerting module registers `ProcessMonitorResult`, a due-pull-retry command/scheduler entry, retry-request outbox handling, queue bindings, and deterministic fakes. A retry-request consumer must execute the actual source probe and submit the new observation under a new operation UUID tied to the same evaluation chain.

### Lifecycle rules

For pull checks:

1. The initial failure persists `healthy → warn` silently.
2. The retry coordinator requests actual rechecks at +10 seconds and +30 seconds.
3. The third actual consecutive failure persists `warn → down`.
4. A successful retry before `down` returns `warn → healthy` silently.
5. From `down`, the first success persists `down → recovering`; the second consecutive success persists `recovering → healthy` and becomes recovery-eligible.

For pushed metrics:

- Counters remain pending until three consecutive samples confirm the same warn or critical band.
- Three warn samples enter `warn`. Three critical samples enter `down`; if needed to preserve canonical history, confirmed `healthy → warn → down` transitions may be written atomically at the same observation time.
- Any interrupting sample resets the candidate counter.
- Recovery requires three consecutive samples at or below the warn threshold minus the configured recovery delta. A down monitor may enter `recovering` on the first qualifying sample but becomes `healthy` only on the third.
- Values oscillating around a threshold produce no down/recovery churn.

### `NotificationIntentCreated`

The transactional outbox event uses contract version `notification-intent.v1` and contains:

- Operation and intent UUIDs.
- `incident|recovery` phase.
- Project and incident-group UUIDs.
- `warn|critical` severity.
- Non-empty affected foundation monitor identities.
- A stable notification thread key shared by incident and recovery.
- A `problems` deep-link filter containing unique monitor UUIDs.
- Opened/emitted timestamps and recovery downtime seconds.

Transport is at least once; consumers deduplicate by operation UUID. Push delivery is not implemented here.

### Grouping semantics

- The first confirmed `down` opens a project-scoped group and schedules collection for 30 seconds. No incident intent exists before the deadline.
- Confirmed downs during collection join that group. At the deadline, exactly one logical incident intent contains all collected members and the maximum severity.
- A confirmed down arriving within five minutes of the group's latest accepted member updates the same group, intent identity, thread key, and live problem filter. It does not create another logical incident intent. A later transition starts a new group.
- Partial recovery creates no intent. The final confirmed healthy member closes the group and creates exactly one recovery intent containing all members and downtime from first confirmed down through final confirmed healthy.

### `IncidentTimelineReadModel`

An authorized monitor query returns current state and entered time; ordered transitions with from/to state, severity, occurrence time, open/final duration, reason, group UUID, and maintenance-suppressed flag; incident groups with open/close time, thread key, and affected identities; and nullable provider-neutral annotation slots. Authorization is project-bound and cannot be overridden by input.

### Owned `monitor-result-transition` seam

Testing-only `POST /__harness/alerting/results` accepts the exact normalized result contract and returns `202 queued`, `200 duplicate`, or `422`. `GET /__harness/alerting/receipts/{operation_id}` reports queue status, persisted state/transitions, scheduled retries, incident groups, notification intents, and the `agent-v2-expanded-monitors` fake-consumer receipt.

The seam proof must cross the real POST API, foundation outbox relay, registered `ProcessMonitorResult` queue job, real queue worker, and real GET receipt API. Directly calling a processor, transition service, or fake consumer does not satisfy acceptance.

## HTTP API contract

### `POST /api/v1/maintenance-modes`

Request:

- JSON headers and either a bearer foundation `ProjectApiToken` or the existing authenticated operator guard.
- Body: operation UUID, `project|global` scope, optional project UUID, integer `duration_minutes` from 1 through 1440, and optional redacted reason up to 200 characters.
- A project token requires `maintenance:write`, derives its project from the token, and must omit `project_uuid`; it cannot request global scope.
- Global scope requires an operator authorized by `MaintenanceModePolicy`.

Responses:

- `201` with UUID, scope, project UUID/null, start/end timestamps, and `active: true`.
- `200` with the same shape for an idempotent replay.
- `401`, `403`, or validation `422` as appropriate.
- `409` when another active request already owns the same scope target.

### `GET /api/v1/maintenance-modes/current`

A token with `maintenance:read` reads only its project. An authorized operator may select a project UUID. The `200` payload reports `silenced`, effective `project|global|null` scope, mode UUID, end time, and redacted reason. Unauthorized requests return `401|403`.

This route is the complete frontend-consumable contract for later status-header implementations; this slice owns no UI.

### `DELETE /api/v1/maintenance-modes/{maintenance_mode}`

A project token with `maintenance:write` may clear only its project mode. A globally authorized operator may clear global mode. Success returns `204` after once-only catch-up evaluation is queued; unauthorized or unknown resources return `401|403|404`.

### CLI parity

`checkybot:maintenance` supports authorized project/global activation with a duration and early clear. It calls the same application actions as HTTP and cannot bypass policy, idempotency, suppression, or catch-up behavior.

## Maintenance behavior

Global/project maintenance is an adapter over the existing snooze mechanism, not a second suppression system. `isSilencedNow` evaluates monitor snooze, project maintenance, and global maintenance through one path. The existing expired-snooze processing delegates maintenance expiry to the same actions.

Transitions and timeline flags continue during maintenance. Incident/recovery intents are suppressed. On expiry or early clear, an atomic claim queues one catch-up evaluation. Current non-healthy monitors produce at most one grouped incident intent per affected project; healthy monitors produce none. Duplicate jobs, scheduler downtime, and concurrent expiry claims cannot duplicate catch-up.

Foundation deploy tokens remain hashed at rest and rotatable/revocable. This slice adds and enforces `maintenance:read` and `maintenance:write`; plaintext tokens and URL/query secrets must never reach logs.

## External watchdog contract

An alerting-local configuration supplies an HTTPS heartbeat URL and bounded timeout. Every minute, an overlap-safe scheduler entry performs an outbound GET with a Checkybot user agent. Any 2xx is success. Missing configuration reports disabled and sends nothing. Timeout, transport error, and non-2xx responses retain only redacted local diagnostics, do not block other scheduled tasks, and allow the next minute's attempt. URL credentials and query values are always redacted.

## Backend milestone: Queued result ingestion, retries, and hysteresis

<a id="backend-result-state-runtime"></a>

### Scope

Implement the normalized interface, DTO validation, idempotent queued processing, actual pull retry-request orchestration, pushed-metric hysteresis counters, transition persistence, outbox wiring, runtime registration, and testing-only seam APIs.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-result-state-runtime/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-result-state-runtime/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-result-state-runtime/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-result-state-runtime/handoff.md`

### Acceptance criteria

- **AC-alerting-reliability-core-1:** Contract and queue tests prove `MonitorResultIngestionInterface` accepts valid pull and push DTOs, rejects source-incompatible or non-finite threshold data, queues one `ProcessMonitorResult` job per operation UUID, and returns duplicate without a second mutation on replay.
- **AC-alerting-reliability-core-2:** Clock-controlled Pest tests prove the initial pull failure enters warn silently, actual producer rechecks are requested at +10 and +30 seconds, only the third actual failure enters down, and success before then returns healthy with zero intents, including downtime shorter than 30 seconds.
- **AC-alerting-reliability-core-3:** Table-driven Pest tests prove push warn/critical entry requires three consecutive same-band samples, healthy recovery requires three samples at or below warn threshold minus the five-point default, interruptions reset counters, and threshold oscillation creates no down/recovery churn.
- **AC-alerting-reliability-core-4:** State component tests prove one project-isolated current state, immutable ordered transitions, atomic transition/outbox writes, and no duplicate or observation-order regression under concurrent processing.
- **AC-alerting-reliability-core-5:** A Pest end-to-end test posts normalized results through the real harness API, executes the registered relay, runs the real queue worker, and observes the agent fake-consumer receipt plus persisted state/transition through the real receipt API without direct processor invocation.

## Backend milestone: Grouped notification intents and incident timeline

<a id="backend-incident-grouping-read-model"></a>

### Scope

Persist groups/memberships/intents, register collection/coalescing jobs and scheduler recovery, produce incident and recovery outbox intents, and expose the timeline read model. Push transport remains excluded.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-incident-grouping-read-model/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-incident-grouping-read-model/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-incident-grouping-read-model/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-incident-grouping-read-model/handoff.md`

### Acceptance criteria

- **AC-alerting-reliability-core-6:** Clock-controlled feature tests prove no intent exists before the 30-second deadline and exactly one intent afterward contains all same-project downs collected in the window, maximum severity, stable thread key, and matching problem filter.
- **AC-alerting-reliability-core-7:** Feature tests prove downs inside the sliding five-minute window update one logical group/intent/thread/filter, a down after the boundary starts a new group, and duplicate deliveries do not change counts.
- **AC-alerting-reliability-core-8:** Feature tests prove partial/recovering members emit nothing and the final confirmed healthy member emits one recovery intent with original thread key, all identities, and complete downtime.
- **AC-alerting-reliability-core-9:** Read-model component tests prove complete transition/duration/group/suppression/annotation-slot output for an authorized project and zero cross-project leakage.
- **AC-alerting-reliability-core-10:** The canonical full-runtime Playwright journey uses real harness APIs, relay, jobs, and queue worker to observe zero intents for a sub-30-second recovery and one incident plus one final recovery intent for a confirmed grouped failure, with integration evidence and no direct processor call.

## Backend milestone: Scoped maintenance mode and expiry catch-up

<a id="backend-maintenance-deploy-api"></a>

### Scope

Implement API/CLI actions, policy, global/project adapters over snooze, hashed deploy-token abilities, active-state reads, early clear, expiry scheduling, and once-only grouped catch-up.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-maintenance-deploy-api/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-maintenance-deploy-api/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-maintenance-deploy-api/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-maintenance-deploy-api/handoff.md`

### Acceptance criteria

- **AC-alerting-reliability-core-11:** API tests prove validation, token and policy status codes, authorized `201`, idempotent `200`, and overlap `409`, including rejection of project-token global or cross-project requests.
- **AC-alerting-reliability-core-12:** Feature tests prove API and CLI parity, correct project/global scope, current-state response, and policy-enforced early clear.
- **AC-alerting-reliability-core-13:** Clock-controlled tests prove transitions and suppression flags persist while maintenance produces zero incident/recovery intents through the single snooze path.
- **AC-alerting-reliability-core-14:** Expiry/clear tests prove one grouped catch-up intent per affected project for current non-healthy monitors, none for healthy monitors, and idempotency under duplicates/concurrency.
- **AC-alerting-reliability-core-15:** Token/scheduler tests prove hashed storage, independent read/write abilities after expiry/revocation/rotation, and overlap-safe catch-up after scheduler/queue unavailability without early expiry.

## Backend milestone: External heartbeat watchdog

<a id="backend-external-watchdog"></a>

### Scope

Implement local configuration, injectable client/fakes, command/job, every-minute overlap-safe scheduling, timeout behavior, and redacted diagnostics.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-external-watchdog/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-external-watchdog/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-external-watchdog/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-external-watchdog/handoff.md`

### Acceptance criteria

- **AC-alerting-reliability-core-16:** Scheduler and HTTP-fake tests prove one bounded-timeout watchdog GET per due minute to configured HTTPS, 2xx success recording, overlap prevention, and coexistence with all alerting scheduler entries.
- **AC-alerting-reliability-core-17:** Failure tests prove disabled behavior when unconfigured and redacted, non-blocking timeout/transport/non-2xx handling followed by a successful next-minute attempt with no URL secret leakage.

## Review expectations

Review must confirm that machine and human contracts agree; all implementation and milestone artifacts stay within inherited ownership; `warn` is used as the canonical degraded state; retries represent real probes; outbox/state/group writes are idempotent and atomic; project isolation holds; notification-intent counts satisfy the PRD; maintenance uses one suppression path and catches up exactly once; the owned seam crosses real HTTP/relay/queue/HTTP boundaries; watchdog failures cannot hide scheduler work; no product frontend or push transport was added; and full-runtime evidence uses only run-scoped harness resources.

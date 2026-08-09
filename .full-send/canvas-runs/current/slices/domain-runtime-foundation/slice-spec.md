# Shared monitor domain, runtime seams, and contracts — slice specification

## Purpose

Establish the single foundation used by every Checkybot monitoring surface and pipeline. This slice owns the vocabulary and persistence for monitor identity, lifecycle state, severity, transitions, outbox delivery, project API tokens, secret handling, and status summaries. It also owns the canonical `GET /api/status-summary` read contract so mobile, WidgetKit, and web clients do not derive different answers.

This is a backend and shared-contract slice. It exports frontend-consumable TypeScript contracts and a contract-only browser fixture, but it owns no product screen; therefore there is no frontend implementation milestone.

## Base requirements

- Canvas foundation mandate: shared monitor identity, type, lifecycle state, severity, transition, status-summary, encrypted secret, redaction, outbox, scheduler, API resource, and test-fake contracts.
- PRD §§4.1–4.3: servers, websites, and APIs are the canonical monitor taxonomy; stored API credentials are encrypted at rest and masked after entry.
- PRD §5.1: one persisted monitor-state and transition-history foundation serves every monitoring pipeline.
- PRD §5.4: machine-facing project APIs use scoped, rotatable per-project API tokens.
- PRD §6.2: the widget and other consumption surfaces share nine counts, report freshness, and treat data older than 15 minutes as stale.
- Canvas integration requirement: component contracts and a full-runtime Playwright journey execute through the canonical harness with the real queue worker running.

## Inherited ownership

Implementation is limited to this exact ownership set:

- `app/Domain/Monitoring/Foundation`
- `app/Domain/Security/Foundation`
- `app/Http/Controllers/StatusSummaryController.php`
- `app/Http/Resources/MonitoringFoundation`
- `app/Models/MonitorState.php`
- `app/Models/MonitorTransition.php`
- `app/Models/OutboxEvent.php`
- `app/Models/ProjectApiToken.php`
- `config/checkybot.php`
- `database/migrations/2026_08_06_000000-2026_08_06_009999`
- `packages/contracts`
- `routes/status-summary.php`
- `tests/Feature/MonitoringFoundation`
- `tests/Unit/MonitoringFoundation`
- Migration timestamp range: `2026_08_06_000000-2026_08_06_009999`

Milestone ledger, manifest, review, and handoff documents are additionally written beneath this slice's own `milestones/` directory.

## Scope boundaries and safety

- This slice defines reusable contracts and infrastructure, not alert transition rules, threshold evaluation, incident grouping, push delivery, agent ingestion, dashboard screens, SDK serializers, or AI provider behavior.
- Monitor types are `server`, `website`, and `api`. Lifecycle states are `healthy`, `warn`, `down`, and `recovering`. Severities are `warn` and `critical`.
- A recovering monitor remains in the summary's `warn` bucket until it becomes healthy. This prevents a not-yet-confirmed recovery from appearing green.
- A missing access log or similar prerequisite is represented by a typed reason/condition code associated with a warn state; downstream slices must not add a fifth lifecycle state.
- Public contract identities use UUIDs. Internal database keys may remain implementation details and must not enter API or event contracts.
- Harness probe routes are test-only and loopback-bound. They must not be registered in production.
- Runtime verification uses only the canonical harness's run-scoped SQLite database and child queue worker. No shared database, Redis server, Supervisor process, or host service may be altered.

## Shared interface contracts

### Monitor identity and transitions

`MonitorIdentity` contains a project UUID, monitor UUID, and one monitor type. `MonitorTransitionData` contains a transition UUID, identity, nullable previous state, next state, nullable severity, RFC3339 UTC occurrence time, idempotent operation UUID, optional typed reason code, and redacted metadata. Transition history is immutable and ordered by occurrence plus operation identity.

`MonitorState` is the system of record for a monitor's current lifecycle state. A unique project/type/monitor key prevents multiple current rows. `MonitorTransition` is the append-only history. Project-scoped type/state indexes support summary reads without cross-project leakage.

### Status summary

`StatusSummaryData` contains exactly the nine server/website/API × healthy/warn/down counts, `updated_at`, and `stale`. PHP DTOs, API resources, generated TypeScript types/clients, and JSON schemas use one set of fixtures and one contract version.

### Check sync

`CheckSyncPayloadContract` is versioned and preserves the package's typed top-level check arrays: uptime, SSL, API, link, OpenGraph, domain-expiry, and response-time-budget definitions. It defines accepted fields and rejects unknown contract versions. The foundation owns the schema; the Laravel SDK slice owns builders and serialization into it.

### Incident and redaction

`RedactionAndIncidentContracts` define bounded incident identifiers, already-redacted diagnostic lines, annotation slots, and safe metadata. The shared redactor handles nested values, query-string values, authorization/cookie headers, configured secret literals, email addresses, and IP addresses. AI contracts can consume only redacted values.

### Outbox and runtime wiring

A domain change and its `OutboxEvent` are written in one database transaction under a unique operation UUID. The registered relay command claims bounded batches, dispatches queue jobs, prevents concurrent double-claims, retries with backoff, and retains redacted terminal-failure metadata. The provider registers the relay command, jobs, contracts, and fakes. An overlap-safe scheduler recovery entry invokes the relay every minute so pending records survive process or queue interruptions.

Deterministic fake consumers expose success, failure, delay, and receipt behavior for alerting, mobile/widget, agent, web, SDK, and AI contract tests. Tests must cross the real HTTP, outbox relay, and queue boundaries; directly invoking a processor or fake consumer does not satisfy seam acceptance.

## HTTP API contract

### `GET /api/status-summary`

Request:

- Header `Accept: application/json`.
- Header `Authorization: Bearer <project API token>`.
- The token must be active, unexpired, and possess `status:read`.
- The token selects the project. No project identifier is accepted from the client.

Responses:

- `200`: `data.counts` contains `servers`, `websites`, and `apis`; each contains non-negative integer `healthy`, `warn`, and `down` fields. `data.updated_at` is an RFC3339 UTC string or null, and `data.stale` is boolean.
- `401`: `{ "message": "Unauthenticated." }` for a missing or invalid token.
- `403`: `{ "message": "This token cannot read status summaries." }` for a valid token without `status:read`.

`updated_at` is the newest state timestamp included for the authenticated project. It is null when the project has no monitor state. `stale` is true when `updated_at` is null or its age is greater than 900 seconds; it remains false through exactly 900 seconds.

### `POST /__harness/monitor-foundation/events`

This route exists only in the canonical testing runtime.

Request body:

- `operation_id`: idempotency UUID.
- `event_type`: `monitor.transitioned`, `contract.check_sync.probed`, or `incident.redaction.probed`.
- `payload`: event-specific object matching the generated contract.

A monitor transition requires project/monitor UUIDs, type, previous/next lifecycle states, severity, and RFC3339 `occurred_at`. A check-sync probe requires its contract version and typed check arrays. A redaction probe requires an incident UUID and bounded log lines.

Responses:

- `202`: operation UUID, `queued`, and event type.
- `422`: standard validation message and field errors.

### `GET /__harness/monitor-foundation/receipts/{operation_id}`

This route exists only in the canonical testing runtime. It returns `404` for an unknown operation. A found operation returns `queued`, `delivered`, or `failed`, plus consumer receipts containing consumer name, contract version, effect, delivery time, and a sanitized payload when relevant.

The test route reports receipt consumers from `alerting`, `mobile`, `widget`, `agent`, `web`, `sdk`, and `ai`. It is a contract fixture, not a production integration endpoint.

## Backend milestone: Shared monitor contracts and persistence

<a id="backend-monitor-contracts"></a>

### Scope

Define canonical PHP contracts and generated TypeScript/JSON schemas for monitor identities, lifecycle states, severity, transitions, summaries, filters, incidents/redaction, outbox envelopes, and check sync. Add foundation migrations/models in the inherited timestamp range and deterministic fake consumer bindings. Connect the testing-only event API to the real outbox relay and queue worker so downstream interface contracts can be proven before consumer slices exist.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/milestones/backend-monitor-contracts/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/milestones/backend-monitor-contracts/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/milestones/backend-monitor-contracts/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/milestones/backend-monitor-contracts/handoff.md`

### Acceptance criteria

- **AC-domain-runtime-foundation-1:** PHP and generated TypeScript/JSON-schema contract tests pass the same fixtures and reject the same invalid values for `server|website|api` identities, `healthy|warn|down|recovering` lifecycle states, `warn|critical` severities, monitor filters, transition envelopes, status summaries, redaction incidents, and versioned check-sync payloads.
- **AC-domain-runtime-foundation-2:** Pest migration and model tests prove one current state per project/type/monitor identity, immutable operation-ordered transition history, UUID public identifiers, project-scoped indexes, valid enum constraints, and unique outbox operation IDs without reading another project's records.
- **AC-domain-runtime-foundation-3:** An end-to-end Pest test proves the `monitor-domain-contracts` and `monitor-domain-to-agent` seams by accepting a typed transition through `POST /__harness/monitor-foundation/events`, executing the registered outbox relay, running the real queue worker, and observing alerting and agent fake-consumer effects through `GET /__harness/monitor-foundation/receipts/{operation_id}`, without invoking a processor or consumer directly.
- **AC-domain-runtime-foundation-4:** An end-to-end Pest test proves the `monitor-domain-to-push` and `monitor-domain-to-web` seams by accepting one typed transition through the real harness API, executing the registered outbox relay, running the real queue worker, and observing mobile, widget, and web fake consumers record the same contract version, monitor identity, state, severity, and filter through the receipt API, without direct consumer invocation.
- **AC-domain-runtime-foundation-5:** An end-to-end Pest test proves the `monitor-domain-to-sdk` seam by accepting a `contract.check_sync.probed` event containing all supported check arrays through the real harness API, executing the relay and real queue worker, and observing an SDK receipt marked schema-valid through the real receipt API; malformed or unknown-version payloads return `422` before enqueueing.

## Backend milestone: Secret safety and outbox runtime conventions

<a id="backend-security-outbox-runtime"></a>

### Scope

Provide encrypted secret/header value objects, hashed and scoped project API tokens, recursive redaction, transactional and idempotent outbox behavior, relay/queue/scheduler registration, safe failure metadata, and deterministic runtime fakes. Connect redaction incidents through the real test API, relay, worker, and AI fake-consumer receipt.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/milestones/backend-security-outbox-runtime/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/milestones/backend-security-outbox-runtime/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/milestones/backend-security-outbox-runtime/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/milestones/backend-security-outbox-runtime/handoff.md`

### Acceptance criteria

- **AC-domain-runtime-foundation-6:** Pest tests prove encrypted secret and header values are ciphertext at rest, decrypt only through the value object, serialize and log as a mask, and never expose plaintext, while `ProjectApiToken` persists only a hash and enforces ability, expiry, revocation, and rotation checks.
- **AC-domain-runtime-foundation-7:** A fixed redaction corpus test proves recursive removal of query-string values, Authorization and Cookie values, configured secret literals, emails, and IPv4/IPv6 addresses from nested strings and arrays while preserving non-sensitive diagnostic structure.
- **AC-domain-runtime-foundation-8:** An end-to-end Pest test proves the `monitor-domain-to-ai` seam by posting `incident.redaction.probed` with every redaction corpus secret through the real harness API, executing the registered relay, running the real queue worker, and observing an AI fake-consumer receipt and `sanitized_payload` through the real receipt API with no original secret present, without invoking the redactor or consumer directly.
- **AC-domain-runtime-foundation-9:** Pest integration tests prove a domain write and its outbox event commit atomically, duplicate operation IDs produce one logical delivery, concurrent relay claims cannot double-deliver, retryable failures increment attempts and become available after backoff, and terminal failures retain inspectable redacted metadata.
- **AC-domain-runtime-foundation-10:** Container-level registration tests prove the foundation provider registers the relay command, queue job and fake bindings, the scheduler invokes the relay every minute with overlap prevention, a stopped queue leaves events pending for the next run, and testing-only probe routes are absent when the application environment is production.

## Backend milestone: Canonical status-summary API and consumer contract

<a id="backend-status-summary"></a>

### Scope

Implement the project-token-authenticated status-summary route, thin controller, read-model query, and API resource. Export the matching generated frontend DTO/client. Connect transition fixtures to summary reads and fake consumer receipts, then prove the web consumer with a contract-only browser fixture in the canonical full runtime. No dashboard, mobile screen, or widget UI is implemented by this slice.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/milestones/backend-status-summary/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/milestones/backend-status-summary/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/milestones/backend-status-summary/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/domain-runtime-foundation/milestones/backend-status-summary/handoff.md`

### Acceptance criteria

- **AC-domain-runtime-foundation-11:** Feature tests prove `GET /api/status-summary` returns `401` for a missing or invalid token, `403` for an active token without `status:read`, and `200` for an active `status:read` token with only the authenticated project's counts in the declared `data.counts`, `data.updated_at`, and `data.stale` shape.
- **AC-domain-runtime-foundation-12:** Clock-controlled feature tests prove healthy, warn, and down states enter their matching cells, recovering enters warn, every absent type/state cell is integer zero, `updated_at` is the newest included state timestamp or null for no state, and `stale` is true for null or age greater than 900 seconds and false through exactly 900 seconds.
- **AC-domain-runtime-foundation-13:** An end-to-end Pest test proves the `status-summary-to-mobile-widget` seam by posting server, website, and API transitions through the real harness API, executing the registered relay, running the real queue worker, observing delivered mobile and widget receipts through the real receipt API, and reading the resulting nine counts and freshness through authenticated `GET /api/status-summary` without invoking the read model or consumer directly.
- **AC-domain-runtime-foundation-14:** The canonical full-runtime Playwright journey proves the `status-summary-to-web` seam by posting typed transitions through the real harness API, executing the relay with the real queue worker running, loading the contract-only browser fixture, and observing its generated web client display the same authenticated `GET /api/status-summary` nine counts, `updated_at`, and `stale` values recorded in the web receipt API response; the journey emits the required integration evidence and never invokes a processor directly.

## Review expectations

Review must verify that machine and human contracts agree, all implementation paths stay within inherited ownership, all milestone artifact paths are unique and slice-local, every owned seam crosses the real API/relay/queue/API boundary, generated PHP and TypeScript contracts share fixtures, no secret reaches logs or receipts, status reads remain project-isolated, and no product frontend or host-level service operation was introduced.

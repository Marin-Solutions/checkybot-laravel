# Feature-flagged AI incident annotations — slice specification

## Purpose

Deliver the PRD v1.5 probable-cause annotation as an optional, asynchronous consumer of confirmed static incidents. AI is never in the check, state-transition, grouping, or notification decision path. A project must explicitly opt in, an eligible server must separately allow sharing already-filtered redacted logs, both project and global monthly budgets must have room, and every line is redacted again immediately before the provider request leaves the host.

The successful output is one bounded, recursively redacted paragraph attached to the existing confirmed-down transition and surfaced through the inherited provider-neutral `root_cause` annotation slot. Disabled, ineligible, denied, over-budget, empty-context, and provider-failure cases leave the slot null and cannot create an alert.

## Base requirements

- PRD §4.1 v1.5: AI runs only after a static rule confirms an incident and may attach one paragraph of probable cause.
- PRD §4.1 v1.5: the feature is disabled by default and enabled per project, not globally.
- PRD §4.1 security: query values, authorization/cookie values, configured secrets, emails, and IP addresses are removed before log content leaves the host.
- PRD §8: AI cannot detect, create, promote, group, or notify an alert.
- Inherited `RedactionAndIncidentContracts`: use foundation identity, transition, redaction, outbox, idempotency, and queue conventions.
- Inherited `IncidentTimelineReadModel`: write against an existing confirmed-down transition and populate its provider-neutral `root_cause` slot.
- Inherited `RedactedLogSnippetProvider`: request only authorized, bounded, opted-in server context and require `alert_eligible=false`.
- Owned seams: register and prove `IncidentConfirmedForAnnotation` and the `RedactedLogSnippetProvider` adapter through real API, relay, worker, and read API paths.
- Verification: opt-in, cap, redaction, no-alert, queue, present/absent component, and canonical Playwright coverage are required.

## Inherited ownership

Implementation is limited to the inherited ownership set, using the repository's package-path equivalent where `app/...` maps to `src/...`:

- `app/Domain/AiAnnotations`
- `app/Http/Controllers/AiAnnotationSettingsController.php`
- `app/Jobs/AiAnnotations`
- `config/ai-annotations.php`
- `database/migrations/2026_08_06_900000-2026_08_06_909999`
- `routes/ai-annotations.php`
- `tests/Component/AiAnnotations`
- `tests/Feature/AiAnnotations`
- `tests/Unit/AiAnnotations`
- Migration range: `2026_08_06_900000-2026_08_06_909999`

Milestone ledger, manifest, review, and handoff artifacts live only in this slice's own `milestones/` directory.

This slice owns no product frontend source file and no design-inventory screen. It must use the existing web-dashboard `MonitorDetail` and `Annotations` components and tokens rather than fork or restyle them. Frontend work is contract and runtime verification under the owned component-test path.

## Scope boundaries and invariants

- Project settings default to disabled and use optimistic versions. Project identity always comes from the authenticated current-project resolver; the API accepts no project override.
- Enabling requires valid HTTPS provider configuration and positive global/project budget caps. Provider credentials are never returned, persisted, or logged.
- Budget is accounted in integer micro-USD against locked UTC-month global and project buckets. A job reserves its maximum possible request cost before provider dispatch; concurrent jobs cannot overspend either cap.
- Each confirmed-down transition operation UUID owns at most one AI operation, provider attempt, settlement, and completed annotation. Duplicate outbox or queue delivery is a no-op.
- Only an existing `server` transition to `down` is eligible. Warn, recovering, healthy, missing, foreign, mismatched, and non-server events are skipped.
- The job rechecks the project flag and transition before spending money. The server's independent `share_redacted_logs` flag must also permit the inherited provider read.
- The AI slice stores only hashes/counts, redaction version, bounded status metadata, usage/cost, and sanitized output. It never persists provider request messages or snippets.
- The provider request contains no project or monitor identifier. It contains only fixed instructions and bounded source/timestamp/redacted-line context.
- `RecursiveRedactor` runs after `RedactedLogSnippetProvider` and immediately before JSON serialization. Provider output is also redacted before persistence.
- HTTPS, no redirects, bounded timeout, bounded lines/chars/output tokens, temperature zero, and the operation UUID idempotency key are mandatory.
- An ambiguous provider acceptance is not automatically sent again. Its reservation remains accounted so the cap remains safe.
- AI processing never inserts or updates monitor state, monitor transitions, incident groups, notification intents, notification outbox events, or push operations.
- Runtime verification uses only the canonical harness's run-scoped SQLite database and child worker processes; it does not alter shared database, Redis, Supervisor, or host services.

## Data contract

The assigned migrations provide:

1. One project setting row with `enabled`, optimistic `version`, and timestamps.
2. UTC-month global and project budget buckets with reserved and spent integer micro-USD amounts.
3. One immutable reservation/settlement record per AI operation.
4. One annotation operation per confirmed-down transition operation UUID, with queued/processing/completed/skipped/failed state and bounded redacted diagnostics.
5. One completed transition-keyed annotation containing the recursively redacted probable-cause paragraph and generation metadata.

Project IDs, transition operation IDs, and unique indexes provide isolation and idempotency. Annotation reads select the latest completed annotation for the monitor's most recent confirmed-down transition. Other inherited annotation slots remain nullable.

## API and interface contracts

### `GET /checkybot/ai-annotations/settings`

The existing authenticated web operator and `CurrentProjectResolver` select the project. No project query/body field is accepted. A missing row reads as `enabled=false`, version zero. The response contains enabled/version, whether non-secret provider configuration is valid, the current UTC budget period and project limit/reserved/spent/remaining values, and `updated_at`. Unauthenticated users follow the existing login redirect; unauthorized context returns 403.

### `PUT /checkybot/ai-annotations/settings`

The CSRF-protected JSON body is exactly `{enabled, version}`. A matching version atomically enables or disables only the current project and increments the version. Stale writes return 409. Invalid input or an attempt to enable without valid HTTPS provider/cap configuration returns 422. The response matches the GET shape and never exposes provider credentials.

### `monitor.transitioned` to `IncidentConfirmedForAnnotation`

The AI-owned `FoundationEventDispatcher` decorator receives the foundation-sanitized outbox event. The event operation UUID must identify a persisted, project/monitor-identical server transition to `down`. The project must currently be enabled. The adapter creates one annotation operation and queues one job, returning `queued`, `duplicate`, or a bounded skip reason. The existing foundation relay and real queue worker are the connecting infrastructure.

### `RedactedLogSnippetProvider::forIncident`

The job constructs `AuthorizedLogSnippetRequest` from the persisted transition: the same authorized project/server identity, a bounded UTC incident window, a configured non-empty subset of nginx/FPM/MySQL, and a limit from 1–200 further restricted by AI config. Denied or empty results skip the provider. Any accepted response must have `alert_eligible=false`; ordered lines are redacted again.

### Outbound provider request

The configured URL must be HTTPS. The request uses the provider credential from runtime config, JSON content type, and the operation UUID as idempotency key. The body contains the configured cheap model, fixed system instructions, bounded already-redacted context, maximum output tokens, and temperature zero. Redirects and body logging are disabled. A valid response contains one paragraph plus non-negative token and billed micro-USD usage no greater than the reservation. All other responses map to typed redacted failures.

### Existing monitor detail read

`GET /checkybot/monitors/{type}/{monitor_uuid}` remains owned by the dashboard slice. Its inherited `annotation_slots` contract returns `root_cause` as the latest completed paragraph or null. Prompts, snippets, credentials, model/provider details, token usage, costs, and failures are never frontend props.

### Harness receipt

`GET /__harness/ai-annotations/receipts/{operation_id}` is loopback-only in testing/harness and absent in production. It reports operation status, bounded snippet metadata, reserved/spent amounts, nullable root cause, generation time, and notification-side-effect evidence. It is used to prove the seams without direct processor invocation.

## Backend milestone: Project opt-in, budget ledger, and redacted provider client

<a id="backend-ai-settings-budget-client"></a>

### Scope

Build project settings, global/project budget reservations and settlement, annotation persistence, the authorized settings API, safe provider abstraction/client, strict pre-egress and post-response redaction, bounded transport, and provider fakes.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/ai-incident-annotations/milestones/backend-ai-settings-budget-client/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/ai-incident-annotations/milestones/backend-ai-settings-budget-client/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/ai-incident-annotations/milestones/backend-ai-settings-budget-client/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/ai-incident-annotations/milestones/backend-ai-settings-budget-client/handoff.md`

### Acceptance criteria

- **AC-ai-incident-annotations-1:** Migration/model tests prove disabled defaults, uniqueness, UTC budget bucketing, and project isolation.
- **AC-ai-incident-annotations-2:** Settings API tests prove auth, CSRF, no project override, optimistic updates, and invalid-configuration behavior.
- **AC-ai-incident-annotations-3:** Clock/concurrency tests prove atomic global/project cap enforcement, exact settlement, no over-cap request, and monthly rollover.
- **AC-ai-incident-annotations-4:** HTTP-capture tests prove the complete secret/PII corpus is absent from outbound payloads, logs, exceptions, and AI storage.
- **AC-ai-incident-annotations-5:** Provider tests prove transport/input/output/idempotency limits and typed safe handling of every declared invalid response.

## Backend milestone: Outbox-to-queue annotation seams and timeline read integration

<a id="backend-ai-annotation-seams"></a>

### Scope

Register the AI module, routes, dispatcher decorator, event adapter, queued annotation job, inherited log-provider binding, timeline annotation-slot read integration, and harness receipt. Prove eligibility, replay safety, failure behavior, and the invariant that AI processing has no alert-side effects.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/ai-incident-annotations/milestones/backend-ai-annotation-seams/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/ai-incident-annotations/milestones/backend-ai-annotation-seams/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/ai-incident-annotations/milestones/backend-ai-annotation-seams/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/ai-incident-annotations/milestones/backend-ai-annotation-seams/handoff.md`

### Acceptance criteria

- **AC-ai-incident-annotations-6:** Registration/dispatcher tests prove only one enabled, persisted server-down transition queues work and every ineligible/duplicate/production-harness case queues none.
- **AC-ai-incident-annotations-7:** Queue tests prove the exact authorized snippet request, `alert_eligible=false`, one redacted transition annotation, and replay/concurrency idempotency.
- **AC-ai-incident-annotations-8:** Before/after snapshots prove successful and failed AI jobs change no lifecycle, incident, notification, outbox-notification, or push data.
- **AC-ai-incident-annotations-9:** Failure tests prove every disabled/denied/empty/over-budget/provider case reaches one stable absent-annotation receipt without duplicate spend.
- **AC-ai-incident-annotations-10:** A Pest end-to-end test enables through real settings HTTP, produces the static incident through real agent HTTP, runs the registered evaluator/relay and real worker, and observes the redacted provider-backed annotation through real receipt and monitor-detail HTTP, with no direct service calls.

## Frontend milestone: Annotation-present and annotation-absent UI contract verification

<a id="frontend-ai-annotation-states"></a>

### Scope

Do not add or fork product UI. Under the owned AI component-test path, verify the existing dashboard annotation component consumes the nullable provider-neutral slot correctly and leaks no AI operational metadata. Run the canonical browser journey through the existing shared monitor-detail UI. No owned screen reference or visual-diff criterion applies.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/ai-incident-annotations/milestones/frontend-ai-annotation-states/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/ai-incident-annotations/milestones/frontend-ai-annotation-states/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/ai-incident-annotations/milestones/frontend-ai-annotation-states/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/ai-incident-annotations/milestones/frontend-ai-annotation-states/handoff.md`

### Acceptance criteria

- **AC-ai-incident-annotations-11:** Component tests prove a non-null root cause renders as one safe readable paragraph with no operational/provider metadata.
- **AC-ai-incident-annotations-12:** Component tests prove every absent state renders explicit unavailability without a fabricated cause, spinner, alert, or unrelated-slot mutation.
- **AC-ai-incident-annotations-13:** The canonical Playwright journey proves opted-out absence, real API opt-in, real static incident production, real relay/worker annotation, UI presence, no AI notification increase, and evidence emission.

## Review expectations

Review must confirm that human and machine specs agree; artifacts and implementation stay within inherited ownership plus required module registration conventions; AI is default-off and project-isolated; both budget buckets are concurrency-safe; no unredacted line crosses the provider boundary; prompt/snippet/provider secrets are never stored or surfaced; every operation is transition-idempotent; only confirmed static server-down events are eligible; denied, empty, over-budget, and provider failures are stable no-annotation outcomes; the timeline returns only provider-neutral slots; no lifecycle or alert records are written by AI; testing routes are absent in production; and both owned seams are proven through real API, registered relay/job, real worker, and real API observation.

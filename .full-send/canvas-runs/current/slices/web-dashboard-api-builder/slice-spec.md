# Inertia React dashboard, incident timelines, and API assertion builder — slice specification

## Purpose

Move Checkybot's operator-facing consumption surface from Filament to Inertia + React using shadcn/ui composition and Tailwind tokens. The slice delivers the canonical nine-number overview, a URL-filtered problem list, project-authorized monitor timelines, the maintenance status banner, and a secure API-monitor assertion builder with live JSON sampling and manual fallback.

Filament may remain for unrelated internal CRUD/admin. Foundation monitor state and summary contracts remain authoritative, and alerting remains authoritative for transitions, groups, durations, suppression metadata, and annotation slots.

## Base requirements

- PRD §7: overview is the same nine numbers as the widget followed by the problem list; monitor detail shows incident transitions, durations, and optional annotation content.
- PRD §4.3: API monitors support status, latency, response-shape, and chained JSON-path assertions. A live JSON sample supplies selectable paths.
- PRD §4.3 security: stored header/token values are application-encrypted, masked after entry, and absent from UI responses, logs, errors, and snapshots.
- PRD §4.3 fallback: fetch, timeout, non-JSON, and upstream authentication failures display a safe error and leave manual JSON-path entry usable.
- PRD §5.4: the status-list header displays the effective project/global maintenance window and “silenced until” time.
- Inherit `MonitorDomainContracts`, `StatusSummaryReadModel`, and `IncidentTimelineReadModel`; do not redefine monitor type, state, severity, freshness, masking, transition, or JSON-schema vocabulary.
- Own the `alerting-to-web-timeline` seam: register the Inertia consumer and testing fixture, then prove it through real HTTP, relay, queue worker, and real Inertia HTTP output.
- Verification includes Pest feature/unit tests, React component tests, and one canonical full-runtime Playwright journey with the real queue worker running.

## Inherited ownership

Implementation is limited to this exact set:

- `app/Domain/ApiMonitorBuilder`
- `app/Http/Controllers/WebDashboard`
- `app/Http/Controllers/ApiMonitorBuilderController.php`
- `database/migrations/2026_08_06_040000-2026_08_06_049999`
- `resources/css/checkybot-dashboard.css`
- `resources/js/Components/CheckybotDashboard`
- `resources/js/Pages/CheckybotDashboard`
- `routes/web-dashboard.php`
- `tests/Component/WebDashboard`
- `tests/Feature/WebDashboard`
- `tests/Unit/ApiMonitorBuilder`
- Migration timestamp range: `2026_08_06_040000-2026_08_06_049999`

Milestone ledger, manifest, review, and handoff artifacts are additionally written only under this slice's own `milestones/` directory.

The package/build manifests and host Inertia bootstrap are not inherited ownership. This slice consumes the canonical runtime's installed Inertia/React/shadcn/Tailwind toolchain and must not modify unrelated owner files.

## Scope boundaries and invariants

- Web controllers contain only request, authorization, and Inertia/JSON response concerns. Dashboard reads and builder writes/fetches live in focused query/action/DTO classes.
- The authenticated operator's current project is authoritative. Browser requests cannot provide a project override, and foreign monitor UUIDs reveal no data.
- The summary uses foundation counts and freshness exactly. `recovering` remains represented in the summary's warn cell.
- Problems are monitor states in `warn`, `down`, or `recovering`; the default includes all three. Reads are bounded and cursor-paginated.
- Timeline rendering consumes the alerting read model. This slice does not write transitions/groups or execute AI annotation jobs.
- Maintenance display consumes the existing maintenance suppression/read path. This slice does not create a second maintenance-state store.
- Builder records are project/API-monitor scoped and versioned. Configuration updates, encrypted-header changes, and ordered assertions are atomic.
- Stored header values never return to JavaScript. A fixed mask and `has_value` are the only read representation.
- Sample fetching is server-side and bounded. Production blocks userinfo plus loopback, private, link-local, reserved, and metadata destinations after DNS resolution and on every redirect. Time, redirect count, header count, and response bytes are capped.
- A deterministic sample upstream may be allowlisted only in the harness environment; this exception and all testing routes are absent in production.
- No design inventory or owned screen references were provided, so no visual-diff criterion is required. UI behavior, responsiveness, accessibility, shared component use, shadcn composition, and Tailwind tokens remain required.
- Runtime verification uses only the canonical harness's run-scoped SQLite state and child queue process; it must not alter shared database, Redis, Supervisor, or host services.

## Backend-to-frontend contract

### Overview — `GET /checkybot`

The existing authenticated web operator guard is required. The current authorized project is resolved server-side.

Optional query fields are unique arrays `types[]` (`server|website|api`), `states[]` (`warn|down|recovering`), `severities[]` (`warn|critical`), and `monitor_uuids[]` (UUID), plus an opaque cursor and `per_page` from 1 through 100 (default 25). Problem states default to all three non-healthy states. Invalid values return the normal Inertia `422` bag.

The `CheckybotDashboard/Overview` props contain:

- Foundation `summary`: the exact 3×3 counts, `updated_at`, and `stale`.
- Bounded `problems`: shared identity, state, severity, observation time, and authorized detail URL.
- Normalized applied filters and cursor pagination.
- Effective maintenance: `silenced`, `project|global|null` scope, end time, and redacted reason.

Unauthenticated requests redirect to login; unauthorized current projects return `403`.

### Monitor detail — `GET /checkybot/monitors/{type}/{monitor_uuid}`

`type` is `server|website|api` and the monitor UUID must exist in the current project. The controller creates `AuthorizedMonitorIdentity` and consumes `IncidentTimelineReadModel`.

The `CheckybotDashboard/MonitorDetail` props contain current state/entered time, ordered transitions with completed or open duration, reason, group UUID, and maintenance suppression, incident-group membership and open/close/thread metadata, and provider-neutral annotation slots with nullable values. Wrong type/unknown monitor returns `404`; a foreign project returns `403` without data.

### Cross-slice read interfaces

`StatusSummaryQuery::forProject` receives only the authorized project UUID and returns foundation counts/freshness. `IncidentTimelineReadModel::forMonitor` receives an authorized identity whose project must match the monitor identity. These backend interfaces are adapted into Inertia props; React does not call PHP services or carry a project token.

### Builder page — `GET /checkybot/api-monitors/{monitor_uuid}/assertions`

The monitor must be an API monitor in the current project. `CheckybotDashboard/ApiAssertionBuilder` receives identity and a versioned configuration: normalized endpoint and method, ordered assertions, and header rows containing only name, a fixed mask, and `has_value`. Initial sample is null. Stored plaintext is never serialized.

### Live sample — `POST /checkybot/api-monitors/{monitor_uuid}/sample`

The authenticated, CSRF-protected JSON request accepts endpoint, method, optional JSON request body, current configuration version, and no more than 50 header operations:

- `preserve`: use an existing encrypted value server-side; no value is sent by JavaScript.
- `set`: replace it with a bounded new value.
- `remove`: omit it and prohibit a value.

A successful bounded JSON response returns `mode=sample`, upstream status, latency, parsed JSON, and ordered canonical JSON paths with inferred types and bounded previews.

Malformed/unsafe input returns `422` with `manual_entry=true`; stale configuration returns `409` with manual fallback. Fetch/transport, upstream `401|403`, non-JSON, and timeout failures return typed `fetch_failed`, `upstream_auth`, `non_json`, or `fetch_timeout` errors under `502|504`, always with a bounded redacted message and `manual_entry=true`. Raw upstream bodies, credentials, and header values are forbidden from responses and logs.

### Save assertions — `PUT /checkybot/api-monitors/{monitor_uuid}/assertions`

The authenticated, CSRF-protected request carries endpoint, method, header operations, configuration version, and at most 50 ordered assertions:

- `status`: `equals|in` with valid HTTP status operand(s).
- `latency`: `less_than_or_equal` with a bounded millisecond integer.
- `json_path`: canonical path up to 512 characters plus `exists|not_null|equals|not_equals|type|non_empty` and an operator-appropriate expected operand.

Path grammar, kind/operator/operand relationships, duplicate headers, counts, endpoint policy, project ownership, and optimistic version are validated before a transaction. `403`, `409`, and `422` produce no partial write. Success returns normalized endpoint/method/assertions, incremented version, and only masked header rows.

## Owned integration seam

This slice owns `alerting-to-web-timeline`. `routes/web-dashboard.php` registers product Inertia routes plus a testing-only, loopback-bound web authentication/fixture path for the canonical harness. The fixture selects a deterministic authorized project but may not bypass the product controller's project checks and must not exist in production.

Seam proof must:

1. Post three confirming monitor results through real `POST /__harness/alerting/results` calls.
2. Execute the registered foundation outbox relay.
3. Run the real queue worker so alerting persists transitions/groups.
4. Authenticate through the real testing fixture.
5. Read the down transition and group from the real `GET /checkybot/monitors/{type}/{monitor_uuid}` X-Inertia response.

Direct result-processor, transition-service, timeline-read-model, controller, or component invocation does not satisfy the seam.

## Backend milestone: Authorized dashboard, problem, and timeline read surface

<a id="backend-dashboard-read-surface"></a>

### Scope

Register authenticated Inertia routes/controllers, bounded problem projection, summary and maintenance adapters, monitor detail, and the testing-only seam fixture. Bind directly to inherited read contracts and preserve project isolation.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/backend-dashboard-read-surface/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/backend-dashboard-read-surface/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/backend-dashboard-read-surface/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/backend-dashboard-read-surface/handoff.md`

### Acceptance criteria

- **AC-web-dashboard-api-builder-1:** Feature tests prove overview authentication/project isolation, canonical summary and maintenance props, default and explicit problem filters, validation, and bounded cursor pagination.
- **AC-web-dashboard-api-builder-2:** Feature tests prove authorized timeline completeness and exact `403|404` behavior for foreign, unknown, and wrong-type monitors.
- **AC-web-dashboard-api-builder-3:** A Pest end-to-end test proves the owned seam through real alerting POST API, registered relay, real queue worker, harness authentication, and real X-Inertia detail response with no direct processor/read-model/controller calls.

## Backend milestone: Secure API assertion builder and live sample endpoints

<a id="backend-api-assertion-builder"></a>

### Scope

Persist versioned builder configuration, encrypted headers, and ordered assertions; implement secure bounded sample fetching, deterministic JSON-path extraction, typed safe errors, optimistic concurrency, and thin HTTP transport.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/backend-api-assertion-builder/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/backend-api-assertion-builder/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/backend-api-assertion-builder/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/backend-api-assertion-builder/handoff.md`

### Acceptance criteria

- **AC-web-dashboard-api-builder-4:** Persistence/masking tests prove project-scoped versioning, encrypted set/preserve/remove behavior, atomic stale-write rejection, and no plaintext in responses, exceptions, logs, or serialization.
- **AC-web-dashboard-api-builder-5:** Sample feature tests prove bounded JSON success, deterministic path output, and server-only use of preserved encrypted headers.
- **AC-web-dashboard-api-builder-6:** Failure tests prove SSRF/redirect blocking, fetch limits, typed manual-fallback failures, and complete credential/body redaction.
- **AC-web-dashboard-api-builder-7:** Table-driven tests prove valid ordered status/latency/JSON-path chains save and invalid operators, operands, paths, duplicates, counts, versions, and project access produce no partial write.

## Frontend milestone: Inertia overview, filtered problems, timeline, and maintenance UI

<a id="frontend-dashboard-timeline"></a>

### Scope

Build reusable shadcn/Tailwind dashboard components and Inertia pages for the nine-count overview, freshness, problem filters/list, monitor timeline, groups, annotations, and maintenance banner. Include loading, healthy, empty, problems, stale, and error behavior with keyboard/focus support.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/frontend-dashboard-timeline/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/frontend-dashboard-timeline/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/frontend-dashboard-timeline/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/frontend-dashboard-timeline/handoff.md`

### Acceptance criteria

- **AC-web-dashboard-api-builder-8:** Component tests prove exact nine-number, freshness, all-healthy, problems, empty-project, loading, and error rendering without stale-green presentation.
- **AC-web-dashboard-api-builder-9:** Component/navigation tests prove URL filter initialization, normalized Inertia updates, filtered rendering, authorized detail navigation, and deep-link persistence.
- **AC-web-dashboard-api-builder-10:** Timeline component tests prove chronological transitions, open/completed durations, state/severity/suppression labels, group members, empty history, and null-versus-present annotations.
- **AC-web-dashboard-api-builder-11:** Maintenance component tests prove active scope/end-time banner behavior, inactive omission, responsive persistence, and keyboard readability.

## Frontend milestone: Interactive JSON-path API assertion builder

<a id="frontend-api-assertion-builder"></a>

### Scope

Build the Inertia React builder for endpoint/method, masked header actions, ordered assertions, live sample JSON tree/path selection, manual fallback, field errors, focus preservation, and optimistic save behavior.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/frontend-api-assertion-builder/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/frontend-api-assertion-builder/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/frontend-api-assertion-builder/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/milestones/frontend-api-assertion-builder/handoff.md`

### Acceptance criteria

- **AC-web-dashboard-api-builder-12:** Component tests prove successful sample status/latency/JSON rendering and keyboard path selection without discarding unrelated draft state.
- **AC-web-dashboard-api-builder-13:** Component tests prove mask-only header rendering and explicit preserve/replace/remove behavior with no stored plaintext in DOM, state, errors, or snapshots.
- **AC-web-dashboard-api-builder-14:** Component tests prove all typed sample, version, and validation failures retain the draft and leave labeled manual JSON-path entry/save usable.
- **AC-web-dashboard-api-builder-15:** Component/request tests prove ordered assertion editing, row-specific errors, non-destructive stale-version handling, and normalized masked success replacement.
- **AC-web-dashboard-api-builder-16:** The canonical Playwright journey with the real worker crosses alerting HTTP/relay/queue into overview/problem/detail, verifies maintenance, and exercises real sample/save plus masked-header preservation and auth-error manual fallback while emitting integration evidence.

## Review expectations

Review must verify machine/human contract parity, exact inherited ownership, unique slice-local milestone paths, project isolation, bounded queries and fetches, no secret exposure, SSRF/redirect defense, atomic optimistic writes, shared enum/schema reuse, the real owned-seam path, and complete unit/feature/component/full-runtime evidence. It must also confirm that the consumption surface is Inertia React/shadcn/Tailwind while unrelated Filament admin remains untouched.

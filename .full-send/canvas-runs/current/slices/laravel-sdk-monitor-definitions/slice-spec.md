# Laravel package monitor definition compatibility — slice specification

## Purpose

Adapt the existing Laravel package declaration and sync surface to the v1 monitor-definition contract. The package will declare domain-expiry checks, p95 response-time budgets, and richer API assertions through both fluent builders and published configuration while preserving existing uptime, SSL, API, dead-link, and OpenGraph usage.

This slice owns declaration, validation, serialization, HTTP sync behavior, safe command output, package documentation, and package tests. It does **not** own storing checks, encrypting downstream database values, sampling endpoints, evaluating thresholds, running WHOIS/RDAP, calculating p95, or changing monitor lifecycle state.

There is no product frontend work or owned screen in this slice, so no frontend milestone is emitted.

## Base requirements

- Canvas mandate: add v1-compatible fluent/config payloads, safe secret handling expectations, compatible summaries, documentation, facade declarations, and backward-compatible tests.
- PRD §4.2: domain expiry warns at 30 days; response-time budget evaluates p95 and warns above the default 2000 ms budget.
- PRD §4.3: API monitors support custom headers and ordered assertions on status, latency, and JSON response shape.
- PRD §4.3 security: credentials are masked after entry and excluded from logs and errors. The SDK must send the real value to the authenticated API, but no package observability surface may disclose it.
- The foundation-owned `CheckSyncPayloadContract` is the wire-schema source of truth. This slice consumes it and must not create a divergent package-local schema.
- Existing package users retain current builders, config sections, options, assertion aliases, registry precedence, and summary compatibility.
- The owned `sdk-to-check-sync-contract` seam is verified across real HTTP, the foundation event API, the registered outbox relay, a real queue worker, and the receipt API in the canonical full-runtime Playwright harness.

## Inherited ownership

Implementation is limited to this exact ownership set:

- `src`
- `config/checkybot-laravel.php`
- `stubs`
- `README.md`
- `CHANGELOG.md`
- `tests/Feature/FluentApiSyncTest.php`
- `tests/Feature/SyncCommandTest.php`
- `tests/Unit/CheckRegistryTest.php`
- `tests/Unit/CheckybotClientTest.php`
- `tests/Unit/ConfigValidatorTest.php`
- `tests/Unit/Checks`
- `tests/Unit/ConfigTest.php`
- Migration timestamp range: `none`

Milestone ledger, manifest, review, and handoff documents are additionally written beneath this slice's own `milestones/` directory.

## Scope boundaries and compatibility rules

- `check-sync.v1` contains seven required arrays, even when some are empty: `uptime`, `ssl`, `api`, `dead_links`, `open_graph`, `domain_expiry`, and `response_time_budget`.
- Existing builder methods keep their signatures and fluent return behavior. Their per-check values are preserved while the aggregate wire envelope becomes versioned and canonical.
- Existing config section names remain accepted. Config and registry paths converge on one serializer and must produce identical wire definitions for equivalent inputs.
- New domain-expiry definitions default `warn_days` to `30`.
- New response-time-budget definitions default `percentile` to `95` and `budget_ms` to `2000`.
- API definitions support ordered metadata for status, maximum latency, and JSON body assertions. Existing fluent aliases remain valid.
- Header/token plaintext exists only in memory and the authenticated outbound request. Safe serialization, dry runs, summaries, exceptions, logs, object debug output, snapshots, and evidence use fixed masks or omit values.
- Production sync requires HTTPS. Plain HTTP is permitted only for the canonical loopback harness.
- Runtime defaults and evaluation behavior remain owned by downstream runtime slices. This package emits definitions only.
- If the checked-in generated foundation contract does not expose all seven arrays or their v1 fields, implementation must report the mismatch to the foundation contract owner; it must not edit `packages/contracts` or copy the schema under package ownership.

## API and integration contracts

### `POST /api/v1/projects/{project_id}/checks/sync`

The SDK sends one JSON request with:

- `Accept: application/json`
- `Content-Type: application/json`
- `Authorization: Bearer <configured API key>`
- body `contract_version: check-sync.v1`
- all seven canonical arrays

The configured project identifier is treated as opaque, non-empty, and URL-encoded. Local validation requires unique names within each type, `FILTER_VALIDATE_URL` plus HTTP(S), intervals matching `/^\d+[smhd]$/`, bounded options and thresholds, and valid API assertion metadata. Local failures prevent the HTTP call.

A production `200` response contains a message and created/updated/deleted summary counts. The command accepts canonical summary keys and compatible legacy aliases (`uptime_checks`, `ssl_checks`, `api_checks`, `link_checks`, `open_graph_checks`, and the equivalent new-type aliases). Missing individual counts normalize to zero rather than causing notices.

The loopback harness may return `202` with an operation UUID and `contract.check_sync.probed` queued status. Authentication failures map from `401`/`403`; contract failures map from `422`; transport failures become a bounded `CheckybotSyncException`. No error or log may contain a configured API key or check credential.

### `POST /__harness/monitor-foundation/events`

This existing foundation route is consumed only in the canonical testing runtime. It is loopback-bound and absent in production. The seam journey posts:

- an idempotent operation UUID;
- event type `contract.check_sync.probed`;
- the **exact** body captured from the SDK's real sync HTTP request.

The foundation contract rejects malformed or unknown-version payloads with `422` before enqueueing and accepts valid payloads with `202`.

### `GET /__harness/monitor-foundation/receipts/{operation_id}`

After `checkybot:foundation-relay` runs and the real queue worker handles delivery, this existing harness route must report `delivered` with an `sdk` receipt, contract version `check-sync.v1`, effect `schema-valid`, and all seven check types. Unknown operations return `404`.

## Backend milestone: V1 fluent and config monitor definition contracts

<a id="backend-sdk-definition-contracts"></a>

### Scope

Add focused domain-expiry and response-time-budget builders and complete registry support. Extend published config and stubs. Enrich API checks with deterministic status, latency, and body assertion metadata. Converge config and registry definitions on one versioned serializer. Expand local validation without taking on runtime evaluator responsibilities, and provide safe representations for secret-bearing definitions.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/laravel-sdk-monitor-definitions/milestones/backend-sdk-definition-contracts/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/laravel-sdk-monitor-definitions/milestones/backend-sdk-definition-contracts/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/laravel-sdk-monitor-definitions/milestones/backend-sdk-definition-contracts/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/laravel-sdk-monitor-definitions/milestones/backend-sdk-definition-contracts/handoff.md`

### Acceptance criteria

- **AC-laravel-sdk-monitor-definitions-1:** Unit and config tests prove domain-expiry and response-time-budget definitions can be declared through both fluent factories and config, serialize identically into their canonical `check-sync.v1` arrays, default to `warn_days=30` and `percentile=95`/`budget_ms=2000`, and preserve valid explicit thresholds.
- **AC-laravel-sdk-monitor-definitions-2:** API-check unit tests prove fluent status, maximum-latency, and chained JSON response-shape assertions serialize as deterministic ordered metadata with stable `sort_order` and `is_active` values and preserve scalar operand types required by the generated contract.
- **AC-laravel-sdk-monitor-definitions-3:** A fixed secret-corpus test proves Authorization, bearer-token, cookie, and custom-header plaintext appears only in the captured outbound HTTPS request body and never in dry-run or summary output, exception text, Laravel logs, object debug output, safe serialization, snapshots, or integration evidence.
- **AC-laravel-sdk-monitor-definitions-4:** Table-driven `ConfigValidator` tests prove fluent and config definitions reject duplicate names per type, URLs failing `FILTER_VALIDATE_URL` or non-HTTP(S) schemes, intervals outside `/^\d+[smhd]$/`, out-of-range domain/budget/status/latency/retry values, and invalid assertion kind/operator/path/operand combinations before any HTTP request is made.
- **AC-laravel-sdk-monitor-definitions-5:** Backward-compatibility tests prove existing `uptime`, `ssl`, `api`, `links`, and `openGraph` facade methods, interval helpers, existing options and assertion aliases, registry precedence over config, count/flush/getters, and legacy config section names continue to produce the same per-check values inside the canonical versioned payload.

## Backend milestone: Secure sync transport, summaries, documentation, and runtime seam

<a id="backend-sdk-sync-compatibility"></a>

### Scope

Update the client and command for the versioned seven-type wire contract, safe transport and failure handling, canonical/legacy summary normalization, and complete dry-run labels. Update README, CHANGELOG, facade docblocks, config, and stubs with working examples and the package/runtime security boundary.

For the owned seam, add only testing/harness wiring under inherited `src` ownership and ensure it is absent in production. The canonical journey captures the exact request emitted by the real SDK route, submits it through the real foundation harness API, runs the registered relay with the real queue worker, and reads the SDK receipt through HTTP. Run-scoped generated Playwright code and evidence may live under `build`; reviewable changes remain inside inherited ownership.

### Milestone artifact paths

- Ledger: `.full-send/canvas-runs/current/slices/laravel-sdk-monitor-definitions/milestones/backend-sdk-sync-compatibility/ledger.md`
- Manifest: `.full-send/canvas-runs/current/slices/laravel-sdk-monitor-definitions/milestones/backend-sdk-sync-compatibility/manifest.json`
- Review: `.full-send/canvas-runs/current/slices/laravel-sdk-monitor-definitions/milestones/backend-sdk-sync-compatibility/review.md`
- Handoff: `.full-send/canvas-runs/current/slices/laravel-sdk-monitor-definitions/milestones/backend-sdk-sync-compatibility/handoff.md`

### Acceptance criteria

- **AC-laravel-sdk-monitor-definitions-6:** `CheckybotClient` tests prove `syncChecks` sends one authenticated JSON POST to `/api/v1/projects/{encoded_project_id}/checks/sync` with `contract_version=check-sync.v1` and all seven arrays, accepts declared `200` and harness `202` responses, maps `401`/`403`/`422` and transport failures to `CheckybotSyncException`, and exposes no secret in logs or thrown messages.
- **AC-laravel-sdk-monitor-definitions-7:** Command feature tests prove registry and config syncs count and dry-run-label all seven check types, never print sensitive fields, and render created/updated/deleted totals correctly for both canonical summary keys and legacy `uptime_checks|ssl_checks|api_checks|link_checks|open_graph_checks` plus new-type `*_checks` aliases, with absent counts normalized to zero.
- **AC-laravel-sdk-monitor-definitions-8:** Documentation contract tests prove README, CHANGELOG, facade docblocks, published config, and stub contain working fluent and config examples for domain expiry, p95 response budgets, and API status/latency/body assertions; identify `check-sync.v1`; explain upgrade compatibility; and state that secrets are sent only to the authenticated HTTPS API for downstream encryption and are masked from package output.
- **AC-laravel-sdk-monitor-definitions-9:** Package contract tests validate the exact fluent-generated and config-generated HTTP bodies against the foundation-owned `CheckSyncPayloadContract` fixtures, accept every current check type and optional field, and reject a missing array, unknown contract version, malformed definition, or package-only unknown top-level key.
- **AC-laravel-sdk-monitor-definitions-10:** The canonical full-runtime Playwright end-to-end test proves the owned `sdk-to-check-sync-contract` seam by defining all seven types through the real package APIs, capturing the exact body emitted by a real `POST /api/v1/projects/{project_id}/checks/sync`, submitting that body as `contract.check_sync.probed` through `POST /__harness/monitor-foundation/events`, executing the registered `checkybot:foundation-relay` command with the real queue worker running, and observing an SDK `schema-valid` receipt listing all seven types through `GET /__harness/monitor-foundation/receipts/{operation_id}`, without invoking a validator, processor, job, or consumer directly.

## Review expectations

Review must prove machine and human contracts agree, all changes remain inside inherited ownership, both milestone artifact path sets are unique and slice-local, no package-local contract schema was introduced, no secret appears outside the outbound request, old declarations remain usable, the seven-type payload validates against the foundation contract, and the owned seam crosses real HTTP, relay, queue worker, and receipt boundaries. No product frontend, database migration, monitor evaluator, or host-service change belongs to this slice.

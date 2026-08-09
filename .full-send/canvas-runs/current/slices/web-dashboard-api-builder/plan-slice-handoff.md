# Web dashboard and API builder slice plan handoff

Proposed the backend/frontend slice specification for `web-dashboard-api-builder`.

Artifacts:

- Machine contract: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/slice-spec.json`
- Human specification: `.full-send/canvas-runs/current/slices/web-dashboard-api-builder/slice-spec.md`

The plan preserves the inherited ownership and migration range, defines seven complete backend/frontend and cross-slice contracts, and splits delivery into two backend and two frontend milestones with 16 unique binary acceptance criteria. It includes the owned `alerting-to-web-timeline` seam proof across the real alerting HTTP API, registered relay, real queue worker, harness authentication, and real X-Inertia monitor-detail response. It also specifies bounded SSRF-safe sample fetching, encrypted/masked header semantics, typed manual fallback, component coverage, and one canonical full-runtime Playwright journey. No visual-diff criterion is emitted because this slice owns no design-inventory screens or reference captures.

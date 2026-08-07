# Alerting reliability core slice plan handoff

Proposed the backend-only slice specification for `alerting-reliability-core`.

Artifacts:

- Machine contract: `.full-send/canvas-runs/current/slices/alerting-reliability-core/slice-spec.json`
- Human specification: `.full-send/canvas-runs/current/slices/alerting-reliability-core/slice-spec.md`

The plan preserves inherited ownership, defines nine HTTP/interface/event contracts, and splits work into four backend milestones with 17 unique binary acceptance criteria. It includes the owned `monitor-result-transition` seam proof through real HTTP, outbox relay, queue worker, and receipt API; no frontend milestone is emitted because this slice owns no frontend files or screens.

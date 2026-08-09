# Push/mobile/widget status slice plan handoff

Proposed the full-stack slice specification for `push-mobile-widget-status`.

Artifacts:

- Machine contract: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/slice-spec.json`
- Human specification: `.full-send/canvas-runs/current/slices/push-mobile-widget-status/slice-spec.md`

The plan preserves the inherited ownership and migration range, defines nine HTTP/event/provider/read-model contracts, and splits delivery into one backend and two frontend milestones with 15 unique binary acceptance criteria. It includes the owned `alerting-to-push-delivery` seam through real alerting HTTP, grouping/outbox infrastructure, the real queue worker, and the real push receipt API; critical/warn payload mapping and the 28-day dual-send proving ledger; typed Expo mobile loading/healthy/problem/offline states; and exactly one iOS WidgetKit 3×3 widget with direct status-summary reads, stale-green prevention, deep links, and refresh acceleration.

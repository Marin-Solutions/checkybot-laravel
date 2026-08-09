# Release hardening slice plan handoff

Proposed the release-hardening specification without implementing product code.

Artifacts:

- Human specification: `.full-send/canvas-runs/current/slices/release-hardening/slice-spec.md`
- Machine contract: `.full-send/canvas-runs/current/slices/release-hardening/slice-spec.json`

The plan preserves the inherited ownership and no-migration boundary. It defines seven consumed HTTP/interface contracts and splits work into one backend and one frontend milestone with nine unique binary acceptance criteria. The release gate is fail-closed around sub-30-second false-alarm prevention, one incident plus one recovery cardinality, independent external-watchdog evidence, 28 consecutive complete critical Expo plus generic-webhook dual-send days, agent-v2 fleet readiness, explicit empty/error/stale surface behavior, and one built-surface Playwright journey through real HTTP with a real queue worker and complete redacted handover evidence. No owned seams or screens exist, so no seam-owned infrastructure or visual-reference criterion applies.

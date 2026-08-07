# Slice planning handoff

Proposed the feature-flagged AI incident annotations slice specification.

Artifacts:

- Human spec: `.full-send/canvas-runs/current/slices/ai-incident-annotations/slice-spec.md`
- Machine contract: `.full-send/canvas-runs/current/slices/ai-incident-annotations/slice-spec.json`

The contract preserves inherited ownership, defines seven API/interface entries, and splits delivery into two backend milestones plus one frontend contract-verification milestone with 13 unique binary acceptance criteria. It explicitly registers and proves both owned seams end to end through real agent/settings HTTP, the registered evaluator/outbox relay, the real queue worker, the agent redacted-log provider, and real receipt/monitor-detail HTTP. No owned screens exist, so no visual criterion applies.

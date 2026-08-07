# Agent v2 expanded monitors slice plan handoff

Proposed the backend-only slice specification for `agent-v2-expanded-monitors`.

Artifacts:

- Machine contract: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/slice-spec.json`
- Human specification: `.full-send/canvas-runs/current/slices/agent-v2-expanded-monitors/slice-spec.md`

The plan preserves inherited ownership, defines five machine/API/interface contracts, and splits work into four backend milestones with 16 unique binary acceptance criteria. It includes the owned `agent-evaluators-to-alerting` seam proof through authenticated agent HTTP, registered evaluator and relay infrastructure, the real queue worker, and the real alerting receipt API in the canonical full-runtime Playwright harness. No frontend milestone is emitted because this slice owns no frontend files, screens, or design references.

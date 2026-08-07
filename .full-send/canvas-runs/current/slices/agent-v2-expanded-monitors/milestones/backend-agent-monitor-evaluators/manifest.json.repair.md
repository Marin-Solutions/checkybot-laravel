# Repair Triage Log — manifest.json (backend-agent-monitor-evaluators)

## Attempt 1 — 2026-08-07

**Validator failure:** `full_send.parse_manifest` rejected the manifest with `manifest_invalid`: `Unknown artifact type "build/agent-evaluator-playwright/evidence.json" in manifest artifacts[1]`. Additionally, the implementation completion message carried no valid `full-send-verification-manifest` fenced block at all.

**What was inspected:** `manifest.json` exists at the expected path and parses as strict JSON. `targets` is `["backend"]` (valid enum). `verification` has one well-formed backend entry with four commands. `artifacts` is `["test_results", "build/agent-evaluator-playwright/evidence.json"]`.

**Root cause:** The producing agent misunderstood the `artifacts` field contract in two ways:

1. It placed a **file path** (`build/agent-evaluator-playwright/evidence.json`) into the `artifacts` array, which accepts only enum slugs from the allowed set: `git_diff, changed_files, branch_info, test_results, lint_results, build_results, command_log, deployment_result, deployment_url, deployment_log_tail, browser_screenshot, browser_console_log, browser_network_summary, figma_visual_diff, maestro_result, emulator_screenshot, criteria_evidence_map, missing_artifact_report`. The ledger shows the intent: the evidence file records criterion-level runtime evidence (evaluation IDs, processed status, transitions), which maps to the enum slug `criteria_evidence_map`.
2. It omitted the required fenced ` ```full-send-verification-manifest ` block at the end of its completion message entirely (the handoff/completion text references evidence in prose but contains no fenced manifest block).

Everything else about the artifact (strict JSON, valid target enum, verification structure) is compliant. This is a schema/enum mistake plus a missing fenced block — squarely in the correctable category.

**Verdict:** retry_recommended (0 prior triage entries; correctable schema mistake).

**Correction instruction for the producing agent (mechanical):**

1. In `manifest.json`, replace the `artifacts` array value `"build/agent-evaluator-playwright/evidence.json"` with the enum slug `"criteria_evidence_map"`, yielding exactly: `"artifacts":["test_results","criteria_evidence_map"]`. Do NOT put file paths in `artifacts` — only enum slugs from the allowed list. The evidence file itself stays on disk at `build/agent-evaluator-playwright/evidence.json` and remains referenced from `ledger.md`; it just must not appear in the `artifacts` enum array.
2. Change nothing else in `manifest.json` — `targets`, `verification`, and the commands are already valid.
3. End the completion message with exactly one fenced block that opens with ` ```full-send-verification-manifest ` and closes with ` ``` `, containing the exact same strict JSON as the corrected `manifest.json` (single JSON object, no comments, no trailing commas, no duplicate block elsewhere in the message).

# Integration review — release-hardening

Verdict: **slice_approved**

Spec reviewed: `.full-send/canvas-runs/current/slices/release-hardening/slice-spec.json`
Task UUID: `1d52423b-cfc3-42de-859b-5374bffacf2c`

## Review basis

- Milestone reviews record pass/fail for every acceptance criterion ID AC-release-hardening-1 through AC-release-hardening-9.
- Integration review re-ran backend release-hardening Pest tests and the full production-shaped runtime journey.
- No destructive database command was run. Runtime migration happened only after printing effective SQLite configuration and verifying a workspace-local run-scoped SQLite database.
- No device verification artifact is present; recorded as **no device evidence** and reviewed on remaining evidence.
- Rework rounds counted from slice/milestone artifacts: 0; rework budget is not exhausted.

## Acceptance criteria verdicts

| ID | Result | Evidence |
|---|---|---|
| AC-release-hardening-1 | PASS | `./vendor/bin/pest tests/Feature/ReleaseHardening --compact` passed 7 tests/188 assertions. Runtime test uses real harness HTTP, real queue runtime, registered relay, alerting receipts, and push receipts to prove a <30s blip has zero intents/deliveries. |
| AC-release-hardening-2 | PASS | Same Pest run proves confirmed multi-monitor critical and recovery produce exactly one grouped incident and one grouped recovery sharing one thread key, with deduplicated accepted Expo and legacy webhook receipts. |
| AC-release-hardening-3 | PASS | Same Pest run proves fail-closed watchdog/proving-period checklist states and signable state through `PushReliabilityReadModel` with controlled clock. |
| AC-release-hardening-4 | PASS | Same Pest run proves fleet readiness manifest rejects incomplete inventory, agent-report.v2/version/60s/prerequisite/canary/token/rollback evidence gaps. |
| AC-release-hardening-5 | PASS | Same Pest run proves docs and templates contain executable commands, thresholds, owners/timestamps, recency/expiry, rollback triggers, Telegram generic-webhook mapping, fleet ordering, and no-secret evidence scanning. |
| AC-release-hardening-6 | PASS | `runtime-stage.mjs component` passed 2 suites/12 tests importing production status components for loading, healthy/empty, problem, and cached offline states. |
| AC-release-hardening-7 | PASS | Component run imports production widget provider/view model and proves age visibility, 900s boundary, and non-green stale/auth/error treatments. |
| AC-release-hardening-8 | PASS | `runtime-stage.mjs build/start/playwright` passed against built Expo web surface served at `http://127.0.0.1:33869`, real Laravel HTTP at `http://127.0.0.1:42687`, and real queue worker PID 695025. Manifest snapshots prove blip suppression, grouped critical Expo/legacy receipts, status-summary refresh, and cached stale/error surface. |
| AC-release-hardening-9 | PASS | `runtime-stage.mjs finalize` wrote `.full-send/canvas-runs/current/handover-checklists/evidence/frontend-final-states-runtime-proof/5b50b870-7b9a-4524-b7d1-a96edeb00fd4/handover-evidence-manifest.json` with required commands, endpoints, SQLite path, worker/job proof, snapshots, summaries, screenshots/trace/logs, watchdog/proving/fleet references, secret scan PASS, and cleanup proof. |

## Contract and ownership review

The slice stays within declared ownership: `docs/release-hardening.md`, `docs/watchdog-and-push-retirement.md`, `.full-send/canvas-runs/current/handover-checklists`, `tests/Feature/ReleaseHardening`, and `tests/Component/ReleaseHardening`. The integrated runtime exercised the relevant backend/frontend contract seams over real HTTP and through a real queue worker; no product routes, migrations, or delivery logic were changed by this slice.

## Conclusion

All acceptance criteria pass with harness evidence. No backend or frontend rework is requested.

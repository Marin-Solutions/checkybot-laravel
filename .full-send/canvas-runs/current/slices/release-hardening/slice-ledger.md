# Slice ledger — release-hardening

Task UUID: `1d52423b-cfc3-42de-859b-5374bffacf2c`
Spec: `.full-send/canvas-runs/current/slices/release-hardening/slice-spec.json`
Outcome: `slice_approved`

## Rework budget

No prior slice-level `integration-review.md` or `slice-ledger.md` existed before this review, and milestone reviews record first-pass approval/no completed changes-requested cycle. Counted rework rounds: **0**. The third-rework blocked threshold does not apply.

## Device evidence

No `<slice_dir>/device/device-review.md` or `<slice_dir>/device/build-failure.md` is present. Recorded as **no device evidence** and judged on the remaining harness evidence, per workflow rule.

## Milestone review status

- Backend milestone `backend-release-gates-runbooks`: approved; review records pass/fail for AC-release-hardening-1 through AC-release-hardening-5.
- Frontend milestone `frontend-final-states-runtime-proof`: approved; review records pass/fail for AC-release-hardening-6 through AC-release-hardening-9.

## Integration verification performed

- `./vendor/bin/pest tests/Feature/ReleaseHardening --compact` — pass; 7 tests, 188 assertions.
- Full production-shaped runtime journey re-run with the real queue worker:
  - `node tests/Component/ReleaseHardening/runtime-stage.mjs prepare`
  - `node tests/Component/ReleaseHardening/runtime-stage.mjs build`
  - `node tests/Component/ReleaseHardening/runtime-stage.mjs component`
  - `node tests/Component/ReleaseHardening/runtime-stage.mjs start`
  - `node tests/Component/ReleaseHardening/runtime-stage.mjs playwright`
  - `node tests/Component/ReleaseHardening/runtime-stage.mjs stop`
  - `node tests/Component/ReleaseHardening/runtime-stage.mjs finalize`
- Latest runtime evidence manifest: `.full-send/canvas-runs/current/handover-checklists/evidence/frontend-final-states-runtime-proof/5b50b870-7b9a-4524-b7d1-a96edeb00fd4/handover-evidence-manifest.json`.

## Database and runtime safety

No destructive database commands were run. The runtime printed effective `database.default=sqlite` and active database path `/home/ploi/workspaces/agent-canvas-b1631f73-b32f-4463-ae9b-0633a2a40625-checkybot-laravel/build/release-hardening-runtime/5b50b870-7b9a-4524-b7d1-a96edeb00fd4/database.sqlite` before the non-destructive migration. The path is workspace-local and run-scoped.

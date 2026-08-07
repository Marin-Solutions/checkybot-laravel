# Review — frontend-expo-browser-harness

Verdict: **APPROVED**

Review round: 2 per milestone ledger; no prior review artifact was present, so the rework budget is not exhausted.

Spec reviewed: `.full-send/canvas-runs/current/slices/test-harness/slice-spec.json`, section `frontend-expo-browser-harness`.

## Independent verification

No destructive database or migration commands were run. The integration command configured and used a run-scoped SQLite database at `build/harness-runs/4dbe8d75-535b-442e-ad44-d4e6fdd2f4c0/database.sqlite` via `HARNESS_DB_CONNECTION=sqlite` / `HARNESS_DB_DATABASE=<run-dir>/database.sqlite`, and asynchronous queue processing flowed through the real `scripts/runtime/queue-worker` wrapper executing `php scripts/harness/artisan queue:work database ...`.

| Command | Exit | Evidence |
|---|---:|---|
| `npm ci --no-audit --no-fund` | 0 | Installed 1048 packages from `package-lock.json`. |
| `npm run harness:frontend:build` | 0 | Expo web export generated `build/harness-fixture/index.html` and the Metro bundle. |
| `npm run harness:test:component` | 0 | Jest reported 1 suite / 5 tests passed for `tests/Browser/Harness/HarnessFixture.test.tsx`. |
| `npm run harness:integration` | 0 | Full integration run `4dbe8d75-535b-442e-ad44-d4e6fdd2f4c0` passed backend start, Expo build, component tests, fixture server, Playwright, frontend stop, backend stop, and proof generation. |
| `scripts/runtime/frontend start ... --port 48797` + `curl http://127.0.0.1:48797/` + `scripts/runtime/frontend stop ...` | 0 | Served the generated fixture and removed `fixture.pid`; recorded fixture PID `3959776` was not alive after stop. |
| TypeScript no-emit + shell/Node syntax + `git diff --check` | 0 | `npx tsc --noEmit ...`, `bash -n scripts/runtime/backend scripts/runtime/frontend scripts/runtime/queue-worker`, `node --check` for both Node harness scripts, and `git diff --check` all exited 0. |

Primary proof artifacts from the reviewed run:

- Proof: `build/harness-runs/4dbe8d75-535b-442e-ad44-d4e6fdd2f4c0/integration-proof.md`
- Queue evidence: `build/harness-runs/4dbe8d75-535b-442e-ad44-d4e6fdd2f4c0/probe-evidence.json`
- Screenshot: `build/harness-runs/4dbe8d75-535b-442e-ad44-d4e6fdd2f4c0/processed.png`
- Trace: `build/harness-runs/4dbe8d75-535b-442e-ad44-d4e6fdd2f4c0/playwright/harness-smoke-Expo-fixture-f70f8-s-and-real-queue-processing/trace.zip`

## Acceptance criteria results

### AC-test-harness-5 — PASS

Evidence: `npm ci --no-audit --no-fund` exited 0; `npm run harness:frontend:build` exited 0 and exported `build/harness-fixture/index.html`; the canonical frontend lifecycle served the fixture at `http://127.0.0.1:48797/`; `scripts/runtime/frontend stop` removed `fixture.pid`; recorded fixture PID `3959776` was not alive after stop.

### AC-test-harness-6 — PASS

Evidence: `npm run harness:test:component` exited 0 with 5/5 tests passing. The tests cover backend-starting, ready, queue-queued, queue-processed, and API-error states using the declared contract fields/unions (`app`, `queue`, `run_id`, queued/processed probe status, UUID probe IDs, ISO timestamps, and null/non-null `processed_at`).

### AC-test-harness-7 — PASS

Evidence: `npm run harness:integration` ran the Laravel server and a real `queue:work` process, served the built Expo fixture, and Playwright opened the fixture. `probe-evidence.json` records readiness (`app=ready`, `queue=ready`), POST `/__harness/queue-probes` status 202 for probe `9ab2e9ad-bbbb-4098-b0eb-1b2428c5e276`, intermediate GET queued responses, final GET status 200 with `status=processed` and `processed_at=2026-08-07T10:46:41.380807Z`. The processed UI screenshot was emitted at `processed.png`.

### AC-test-harness-8 — PASS

Evidence: The single `npm run harness:integration` command exited 0 only after backend startup, real queue worker readiness, Expo build, component tests, static fixture startup, Playwright smoke journey, frontend stop, backend stop, and owned-PID cleanup checks. The generated proof at `build/harness-runs/4dbe8d75-535b-442e-ad44-d4e6fdd2f4c0/integration-proof.md` was derived from `.full-send/canvas-runs/current/integration-verification-template.md` and contains run ID, commit, command timestamps/exits, endpoint URLs, queue probe evidence, PASS summary, log paths, screenshot path, and trace path. Post-run PID checks found fixture pid file absent and app/worker PIDs not alive.

## Final decision

All acceptance criteria passed with reproducible runtime evidence. No changes requested.

# Backend release gates and runbooks ledger

Task: `f6b32cd0-fea0-4788-9159-f949bae3c246`
Milestone: `backend-release-gates-runbooks`
Outcome: completed

## Scope and ownership

Implemented only the owned release-hardening docs, handover checklists, and Pest tests. No product routes, jobs, models, migrations, delivery code, host services, or runtime scripts were changed. Tests use UUID-scoped SQLite databases; no destructive database command was run.

## Acceptance evidence

| Criterion | Implementation and evidence | Result |
|---|---|---|
| AC-release-hardening-1 | `ReleaseGateRuntimeTest.php` starts the canonical loopback backend and real database queue worker, makes real alerting POSTs for a 20-second pull blip, invokes the registered relay, reads alerting receipts, and proves push receipt 404s/no intents. | Pass |
| AC-release-hardening-2 | The runtime test sends two monitors through three-sample critical confirmation and three-sample recovery, reads one incident + one recovery with a shared thread, replays all operations, and proves one accepted Expo plus one accepted legacy webhook per intent through push receipt HTTP. | Pass |
| AC-release-hardening-3 | `ProvingAndFleetContractTest.php` uses a controlled clock and the production `PushReliabilityReadModel`; missing/stale/failed/dependent watchdog, 27-day window, and failed/missing pair cases all block, while current independent watchdog + 28 complete days signs. | Pass |
| AC-release-hardening-4 | `FleetReadinessContract` and mutation tests fail missing inventory, report/schema/interval/version, nginx/FPM/MySQL exception, cap, canary, scoped rotation, rollback owner, and heartbeat procedure evidence. | Pass |
| AC-release-hardening-5 | Documentation and template contract tests verify commands, thresholds, evidence paths, owners/timestamps, expiry, rollback, Telegram mapping, fleet order, and fail-closed secret scanning. | Pass |

## Verification rounds

| Command | Exit | Evidence |
|---|---:|---|
| `find tests/Feature/ReleaseHardening -name '*.php' -print0 \| xargs -0 -n1 php -l` | 0 | All 4 PHP files reported no syntax errors before formatting. |
| `./vendor/bin/pest tests/Feature/ReleaseHardening --compact` | 0 | 7 passed, 188 assertions; `build/release-hardening/targeted-pest.log`. |
| `./vendor/bin/pint --test tests/Feature/ReleaseHardening` | 0 | Passed; `build/release-hardening/pint.log`. |
| `./vendor/bin/pest --compact` | 0 | 357 passed, 2446 assertions; `build/release-hardening/full-pest.log`. |
| JSON parse check for all `handover-checklists/v1/*.json` | 0 | Three templates valid. |
| `git diff --check` for owned paths | 0 | No whitespace errors. |

Reviewable verification manifest: `.full-send/canvas-runs/current/handover-checklists/evidence/backend-release-gates-runbooks/verification-manifest.v1.json`.

## Files

- `docs/release-hardening.md`
- `docs/watchdog-and-push-retirement.md`
- `.full-send/canvas-runs/current/handover-checklists/README.md`
- `.full-send/canvas-runs/current/handover-checklists/v1/*.json`
- `.full-send/canvas-runs/current/handover-checklists/evidence/backend-release-gates-runbooks/verification-manifest.v1.json`
- `tests/Feature/ReleaseHardening/*`

No blockers remain.

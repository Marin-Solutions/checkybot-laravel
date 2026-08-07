# Backend maintenance deploy API ledger

Milestone: `backend-maintenance-deploy-api`

## Scope delivered

- Added project/global maintenance persistence and scope serialization in migration `2026_08_06_010200`.
- Added hashed project-token/API authentication, operator policy authorization, API controller/routes, and the shared `CreateMaintenanceMode` action used by `checkybot:maintenance`.
- Added effective global/project maintenance lookup through the single `MaintenanceSilencer::isSilencedNow` decision.
- Integrated that decision into confirmed alerting transitions so transitions remain persistent and are marked `maintenance_suppressed`, while existing incident grouping suppresses notification intents.
- Added early-clear and overdue-expiry catch-up queueing, overlap-safe scheduling, atomic once-only claims, and grouped per-project current-state catch-up intents.
- Registered the maintenance provider through the owned alerting provider seam.

## Review-round correction

The round-one review requested real queue-worker proof for AC-13 through AC-15. The maintenance runtime now uses a workspace-local SQLite `database` queue and independent PHP processes executing the registered `queue:work` command. It no longer fakes the queue or directly invokes result processors, grouping actions, catch-up actions, or job `handle()` methods for these proofs.

`MaintenanceModeQueueRuntimeTest.php` additionally proves the registered alerting/outbox paths through real workers. Its duplicate-expiry proof assigns one real `ProcessMaintenanceCatchUp` job to each of two independent queue workers. Each job reaches a workspace-bound barrier before either claim proceeds, producing a deterministic simultaneous claim race and one catch-up effect.

## Acceptance evidence

| Acceptance criterion | Evidence |
|---|---|
| AC-alerting-reliability-core-11 | `MaintenanceModeRuntimeTest.php`: validation, 401 invalid/missing auth, exact 403 scope/ability responses, authorized 201, replay 200, and overlap 409. |
| AC-alerting-reliability-core-12 | API/CLI action reflection and runtime creation, project/global silencing isolation, GET effective scope/expiry, and token/operator DELETE policy coverage. |
| AC-alerting-reliability-core-13 | Clock-controlled results enter through the ingestion contract and are consumed by a real database queue worker. Warn/down/healthy transitions persist with timeline suppression flags, registered incident-transition jobs are consumed, and incident/recovery intent counts remain zero. The dedicated queue runtime also runs the real foundation relay and delivery worker. |
| AC-alerting-reliability-core-14 | Expiry and early-clear dispatch registered `ProcessMaintenanceCatchUp` jobs. Real workers re-evaluate persisted state, emit one complete incident per affected project, ignore healthy-only state and duplicate jobs, and preserve one effect under two barrier-synchronized independent workers whose actual queued jobs reach the claim gate before release. |
| AC-alerting-reliability-core-15 | Foundation hash-only token persistence, independent read/write abilities, expired/revoked/rotated token denial, durable overdue queueing while no worker is available, later real-worker catch-up, active-record exclusion, and minutely `withoutOverlapping` scheduler coverage. |

## Verification results

- `./vendor/bin/pest tests/Feature/MaintenanceMode/MaintenanceModeQueueRuntimeTest.php --compact` — exit 0; 3 tests, 42 assertions. Log: `build/maintenance-mode-queue-runtime-rework.log`.
- `./vendor/bin/pest tests/Feature/MaintenanceMode --compact` — exit 0; 9 tests, 147 assertions. Log: `build/maintenance-mode-real-worker-pest.log`.
- `./vendor/bin/pest --compact` — exit 0; 259 tests, 1032 assertions. Log: `build/maintenance-mode-full-pest.log`.
- `composer analyse -- --no-progress` — exit 0; no errors.
- Owned-file Pint check — exit 0.

Initial rework attempts exposed a race in readiness observation. The final proof starts each queue-specific worker independently and synchronizes inside the actual serialized `ProcessMaintenanceCatchUp` jobs before releasing either claim. The dedicated proof passed repeatedly, then the complete maintenance and full-suite rounds passed.

## Data safety

No Artisan migration command or destructive database operation was run. Tests create UUID-named SQLite files under `build/maintenance-mode-tests/` and `build/maintenance-queue-runtime-tests/`, invoke only additive migration `up()` methods against those files, validate child-process database paths remain inside the workspace, disconnect, and delete each throwaway file.

## Ownership note

All implementation changes are within the declared maintenance/alerting/controller/policy/routes/migration/test ownership. The pre-existing untracked `.env.example` was observed and left untouched.

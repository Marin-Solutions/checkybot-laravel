# Scoped maintenance mode and expiry catch-up — handoff

Implemented AC-alerting-reliability-core-11 through AC-alerting-reliability-core-15 and addressed the round-one async-proof finding.

## Delivered

- Exact maintenance POST/current/DELETE API with hashed project-token and operator policy authorization.
- Shared API/CLI create action, project/global effective silencing, and transition suppression flags through the existing grouping suppression seam.
- Once-only early-clear/expiry catch-up with one grouped current-state incident per affected project.
- Overlap-safe expiry scheduler and token lifecycle enforcement.
- Real database queue-worker proofs for maintenance-suppressed transition processing, foundation outbox relay, early-clear/expiry catch-up, missed-worker recovery, and active-record exclusion.
- Two independent queue workers run duplicate `ProcessMaintenanceCatchUp` jobs to an in-job barrier before release, proving the concurrent claim remains once-only.

## Verification

- Dedicated queue runtime: 3 passed, 42 assertions.
- Combined maintenance runtime: 9 passed, 147 assertions.
- Full Pest suite: 259 passed, 1032 assertions.
- PHPStan: no errors.
- Pint maintenance tests: passed.
- Evidence ledger: `.full-send/canvas-runs/current/slices/alerting-reliability-core/milestones/backend-maintenance-deploy-api/ledger.md`.

No destructive database command was run; all runtime tests used workspace-local throwaway SQLite files.

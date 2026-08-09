# Handoff: grouped notification intents and incident timeline

Outcome: completed

Implemented AC-alerting-reliability-core-6 through AC-alerting-reliability-core-10 within alerting ownership.

## Delivered

- Persistent project-scoped incident groups, memberships, transition links, and notification intents.
- Thirty-second collection and sliding five-minute coalescing with queued jobs plus overlap-safe scheduler recovery.
- Exactly one logical incident and one final grouped recovery intent, deterministic idempotency keys, stable thread keys, full affected identities, and live problem filters.
- Silent sub-30-second recovery and duplicate-job/delivery idempotency.
- Authorized `IncidentTimelineReadModel` with ordered durations, groups, suppression metadata, and nullable annotation slots.
- Receipt API incident data and an alerting-owned outbox consumer adapter; no push transport.
- Real HTTP + SQLite database queue worker + relay Playwright proof at `build/incident-grouping-runtime/evidence.json`.

## Verification

- Full Pest suite: 250 passed, 885 assertions.
- PHPStan: no errors.
- Pint: passed.
- Runtime Playwright: 1 passed; staged runtime stopped cleanly.

See `ledger.md` for criterion-by-criterion evidence and exact command results. The machine-readable verification declaration is in `manifest.json`.

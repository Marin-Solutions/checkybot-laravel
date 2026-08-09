# Handoff — Stateful agent v2 collection and report ingestion

Outcome: completed

Implemented the owned agent v2 collection and ingestion milestone:

- Stateful, locked, atomic mode-0600 collector with semantic versioned payloads and correct baseline/ready/reset network semantics.
- Static FPM/nginx aggregation and local sensitive-data redaction.
- Strict authenticated `POST /api/v2/agent-reports` runtime with project-derived authorization, immutable idempotency, atomic normalized persistence, and after-commit evaluator queue dispatch.
- Registered-server defaults and project-isolated evaluation-time link-cap overrides.
- Reversible migration in the assigned `030000` range and acceptance-tagged unit/feature coverage.

Verification is green:

- Agent v2 targeted suite: 9 tests, 151 assertions.
- Full backend suite: 279 tests, 1377 assertions.
- Pint and PHP syntax checks pass.

Database safety was preserved: no Artisan migration or destructive database command was run; tests used isolated in-memory SQLite.

See `ledger.md` for criterion-level evidence and command exit codes, and `manifest.json` for executable verification.

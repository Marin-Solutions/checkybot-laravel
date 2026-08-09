# Handoff — registered-device push delivery and proving ledger

Completed the backend push milestone and addressed review round 1.

## Delivered
- Authenticated, project-authorized registration/rotation and owner-only idempotent deactivation API.
- Encrypted Expo tokens, active-device selection, and token supersession.
- `notification-intent.v1` operation claiming, per-device Expo delivery, critical generic-webhook proving delivery, unique attempts, bounded retry/backoff, and exact-token deactivation.
- Secret-free critical/warn payloads with badge, typed problem deep-link data, content availability, and widget refresh hint.
- Deduplicated proving receipts and a 28-day reliability read model that excludes the current incomplete UTC date.
- Testing-only loopback push receipt API.

## Rework evidence
- AC2 now uses two barrier-synchronized independent real `queue:work` processes for duplicate operation claims and real workers for fan-out, retries, terminal token deactivation, and accepted-ticket replay.
- AC4 now produces critical dual-send, warn push-only, duplicate processing, missing-pair failure, and 28 daily proving records via real queue workers.
- The partial-day assertion proves 27 eligible days at `2026-08-07T12:00:00Z`; readiness becomes true only at `2026-08-08T00:00:00Z`.

## Verification
- Push feature suite: 8 passed, 146 assertions.
- Full Pest suite: 270 passed, 1226 assertions.
- PHPStan: no errors.
- Pint: passed.

Evidence and exit codes are recorded in `ledger.md` and `build/push-rework-*.log`. No destructive database commands were used.

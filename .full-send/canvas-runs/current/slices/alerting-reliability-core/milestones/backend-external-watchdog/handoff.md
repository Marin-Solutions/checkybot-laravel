# External heartbeat watchdog handoff

Implemented `backend-external-watchdog` and completed both owned acceptance criteria.

## Delivered

- Injectable, bounded-timeout HTTPS heartbeat client.
- Redaction-safe watchdog action and `checkybot:watchdog` command.
- Explicit disabled behavior with no outbound request.
- Every-minute overlap-safe scheduler registration coexisting with all alerting reliability schedules.
- HTTP fake, clock, mutex, recovery, scheduler-isolation, and diagnostic-redaction tests.

## Verification

- Targeted: 3 tests, 48 assertions passed.
- Full suite: 262 tests, 1080 assertions passed.
- PHPStan and Pint passed for the changed implementation.
- No database migration or destructive database command was used.

See `ledger.md` for the AC-by-AC evidence map and command results.

# Handoff — Secret safety and outbox runtime conventions

Implemented AC 6–10 within the domain-runtime-foundation ownership.

## Delivered

- Ciphertext-only, masked secret/header value objects and scoped rotatable project token lifecycle.
- Fixed-corpus recursive redactor covering query/header/cookie/literal/email/IP data.
- Real harness → relay → database queue worker → AI fake receipt flow with sanitized payload only.
- Atomic/idempotent outbox acceptance, lease-based concurrent claims, availability-guarded pending/processing CAS, retries/backoff, and redacted terminal metadata.
- Review repair: worker-side claims now honor `available_at`; a real relay + `queue:work` test proves a duplicate queued message cannot bypass backoff and proves terminal metadata on the subsequent due attempt.
- Registered relay command/job/fakes and overlap-safe every-minute scheduler recovery.
- True barrier-synchronized two-process SQLite race proof and production-route isolation proof.

## Verification

- Full Pest suite: 235 passed, 725 assertions.
- PHPStan: no errors.
- Contract fixture parity: 16 fixtures verified.

No unsafe database command was used. Tests create only workspace-local temporary SQLite databases.

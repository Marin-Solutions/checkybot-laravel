# Backend AI settings, budget, and provider ledger

## Scope

Implemented only `backend-ai-settings-budget-client` using package-path equivalents under `src/Domain/AiAnnotations`, the assigned migration range, controller, route, configuration, and owned tests. No dashboard or product frontend source was changed.

## Acceptance evidence

- **AC-ai-incident-annotations-1** — `AiSettingsAndModelsTest.php` migrates a per-test workspace-local SQLite database and proves disabled/version-zero defaults, project-setting uniqueness, transition-operation uniqueness for operations and completed annotations, immutable completed annotations, UTC scope/month budget uniqueness, and project-scoped reads/updates. The schema is in `database/migrations/2026_08_06_900000_create_ai_annotation_tables.php`.
- **AC-ai-incident-annotations-2** — Feature coverage proves unauthenticated GET/PUT redirects, exact forbidden current-project handling, absent-setting response, rejected project/query overrides and unknown fields, matching-version enable/disable, stale 409, real production-mode web CSRF 419, and 422 for HTTP/missing-cap provider configurations. Routes use existing `web` and `AuthenticateWebOperator` middleware.
- **AC-ai-incident-annotations-3** — The first implementation round included `Support/budget-racer.php`: two independent PHP processes bootstrap the real package against the same verified test-owned SQLite file, synchronize on a filesystem barrier, then race distinct reservations. Tests prove only one reservation fits simultaneous project/global caps, both buckets remain bounded, over-cap generation sends zero HTTP requests, settlement is exactly once, and September starts independently from August. Eight additional local race repetitions passed while hardening SQLite lock retry behavior.
- **AC-ai-incident-annotations-4** — HTTP capture seeds query values, Authorization/Cookie values, runtime/configured secrets, email, IPv4, and IPv6. Assertions prove they are absent from the serialized body, non-credential headers, and AI persistence while `nginx` and its RFC3339 timestamp remain. The provider boundary contains no logging or exception-report calls and emits only typed static failure codes.
- **AC-ai-incident-annotations-5** — Provider tests prove HTTPS-only configuration, fixed bounded connect/request timeouts, redirects disabled, no retries, bounded line/input/response/output sizes, temperature zero, model, and operation UUID idempotency header. Timeout, transport, 429, redirect, malformed JSON, multi-paragraph, oversized, and over-reservation responses return typed redacted failures. A successful recursively redacted paragraph is settled/persisted once; replay sends no second request or charge.

## Verification results

| Command | Exit | Result |
| --- | ---: | --- |
| `vendor/bin/pest tests/Feature/AiAnnotations --compact` | 0 | 13 tests, 125 assertions passed (`build/backend-ai-settings-budget-client-pest.log`) |
| `vendor/bin/phpstan analyse --no-progress` | 0 | No errors (`build/backend-ai-settings-budget-client-phpstan.log`) |
| `vendor/bin/pint --test` | 0 | Passed (`build/backend-ai-settings-budget-client-pint.log`) |
| `vendor/bin/pest --compact` | 0 | 337 tests, 2123 assertions passed before the final additional replay assertions (`build/backend-ai-settings-budget-client-full-pest.log`) |

All database tests create unique SQLite files under `build/` and delete them afterward. No Artisan migration or destructive database command was run.

# Handoff: Project opt-in, budget ledger, and redacted provider client

Outcome: completed

## Delivered

- Added AI annotation tables/models for project settings, UTC project/global budget buckets, reservations, operations, and immutable transition-keyed annotations.
- Added optimistic current-project settings GET/PUT endpoints with existing web operator authorization, CSRF, unknown-field rejection, stale-version handling, and safe configuration/budget summaries.
- Added atomic dual-bucket reservation, idempotent settlement/release, bounded SQLite contention retry, and month isolation.
- Added the `AiAnnotationProvider` binding and bounded HTTPS implementation with no redirect/retry/logging, operation idempotency, immediate recursive pre-egress redaction, strict response validation, and typed redacted failures.
- Added `GenerateIncidentAnnotation` as the action boundary available to the subsequent queue/seams milestone; raw snippets/prompts and provider metadata are never persisted.
- Registered `AiAnnotationsServiceProvider` from the package provider and loaded `routes/ai-annotations.php` plus `config/ai-annotations.php`.

## Downstream integration notes

The queue/seams milestone can inject `GenerateIncidentAnnotation`, or inject `BudgetLedger` and `AiAnnotationProvider` separately if its job lifecycle needs explicit state control. It should supply the persisted transition operation UUID, authorized project UUID, and only lines returned by `RedactedLogSnippetProvider`. `AiAnnotationOperation` already exposes project-scoped status fields and uniqueness constraints.

Verification is recorded in the sibling `ledger.md`; all targeted tests, PHPStan, Pint, and the full Pest regression suite passed.

# Review: backend-ai-settings-budget-client

Verdict: **review_approved**

Review round: 1 (no prior review artifact found).

## Verification rerun

| Command | Result | Evidence |
| --- | --- | --- |
| `vendor/bin/pest tests/Feature/AiAnnotations --compact` | Pass | 13 passed, 125 assertions |
| `vendor/bin/phpstan analyse --no-progress` | Pass | No errors |
| `vendor/bin/pint --test` | Pass | passed |
| `vendor/bin/pest --compact` | Pass | 337 passed, 2126 assertions |

No destructive Artisan/database commands were run.

## Acceptance criteria

| ID | Status | Evidence |
| --- | --- | --- |
| AC-ai-incident-annotations-1 | Pass | Migration is in the assigned `2026_08_06_900000` range and defines `project_id` unique settings, `transition_operation_id` unique operations/annotations, unique `scope_key + period` budget buckets, and reversible `down()` (`database/migrations/2026_08_06_900000_create_ai_annotation_tables.php:13-78`). Model scopes enforce project filtering (`src/Domain/AiAnnotations/Models/*`). `AiSettingsAndModelsTest.php:52-92` verifies disabled/version-zero defaults, duplicate constraints, immutability, and project-scoped reads/writes. Targeted and full Pest suites passed. |
| AC-ai-incident-annotations-2 | Pass | Routes use `web` + existing `AuthenticateWebOperator` middleware (`routes/ai-annotations.php:9-16`). Controller rejects query/project overrides and unknown fields, uses `CurrentProjectResolver`, returns stale-version 409, and validates HTTPS provider/positive caps before enabling (`src/Http/Controllers/AiAnnotationSettingsController.php:27-76`). `AiSettingsAndModelsTest.php:95-141` covers unauthenticated redirects, unauthorized project context, absent setting, project override rejection, enable/disable with matching version, stale 409, missing CSRF 419, and invalid config 422. |
| AC-ai-incident-annotations-3 | Pass | `BudgetLedger::reserve` creates/locks UTC month buckets and atomically claims both project and global caps before a reservation row is created; `settle` and `release` are transactional/idempotent (`src/Domain/AiAnnotations/Actions/BudgetLedger.php:17-151`). `AiSettingsAndModelsTest.php:145-210` uses two independent PHP processes against the same SQLite file to prove simultaneous reservations stay within caps and a new UTC month starts independently. `ProviderClientTest.php:129-141` proves over-cap execution sends zero HTTP requests, and settlement replay returns false. |
| AC-ai-incident-annotations-4 | Pass | Provider egress applies the foundation redactor plus runtime/configured secret redaction immediately before serialization and persists only the redacted result (`src/Domain/AiAnnotations/Support/BoundedHttpsAiProvider.php:92-133`, `src/Domain/AiAnnotations/Actions/GenerateIncidentAnnotation.php:63-97`). `ProviderClientTest.php:56-120` seeds query values, auth/cookie values, configured/provider secrets, email, IPv4, and IPv6; captured request body, non-credential headers, and AI persistence exclude them while retaining source label/timestamp. Code inspection found no `Log::`, `logger`, `report`, `dump`, or `dd` calls under `src/Domain/AiAnnotations`. |
| AC-ai-incident-annotations-5 | Pass | Provider configuration requires HTTPS and non-empty credential/model/timeouts (`AiConfiguration.php:7-34`); outbound client sets bounded connect/request timeouts, disables redirects, does not configure retries, sends `Idempotency-Key`, temperature 0, model, and capped output tokens (`BoundedHttpsAiProvider.php:32-55`, `:132-133`). `ProviderClientTest.php:68-157` verifies HTTPS-only/no-request paths, idempotency header, capped payload, timeout/transport/rate-limit/redirect/malformed/multi-paragraph/oversized/over-reservation typed failures, and successful one-paragraph redaction with exactly one settlement/persistence on replay. |

## Findings

No changes requested.

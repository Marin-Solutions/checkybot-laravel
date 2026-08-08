# Checkybot handover checklists

Copy, never edit, a template from `v1/` into `evidence/<run-id>/`. Replace every placeholder, retain `contract_version`, and record responsible owners and UTC timestamps. The templates are fail-closed: `false`, zero proving days, placeholder text, missing evidence, expired timestamps, or a nonzero secret scan prevents signing.

Execution, thresholds, expiry rules, rollout ordering, rollback triggers, and the no-secret scan are defined in `docs/release-hardening.md`. External-watchdog independence and the generic-webhook Telegram mapping are defined in `docs/watchdog-and-push-retirement.md`.

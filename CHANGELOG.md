# Changelog

All notable changes to `checkybot-laravel` will be documented in this file.

## v0.2.0 - 2026-07-31

Adds the supported runtime component status contract for already-declared aggregate components.

- Adds authenticated `CheckybotClient::reportComponentStatus()` with RFC3339 UTC normalization, allowlisted bounded metrics, input validation, configured timeout, and retry handling.
- Keeps declaration sync separate and backward-compatible; runtime fields are never added to sync payloads.
- Documents the coordinated Checkybot endpoint: `POST /api/v1/projects/{projectId}/components/{componentKey}/status`.
- The compatible Checkybot server implementation is commit `0ee9aa61a6ec2ddcd4396b0d40ce9752db53110e` in `marin-solutions/checkybot`.
- Consumers should upgrade with `composer require marin-solutions/checkybot-laravel:^0.2.0` only after the compatible Checkybot server endpoint and observation migration are deployed.

## v0.1.1 - 2026-07-21

Adds expected HTTP status and per-check retry configuration for API monitors.
